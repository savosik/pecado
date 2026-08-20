<?php

namespace App\Services\Notifications\Pulse;

use App\Enums\ClientContactRole;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Правила пульта: сборка, сохранение и человекочитаемое представление.
 *
 * Пересказ условий обычными словами живёт здесь, а не во фронтенде: набор
 * полей и операторов определяется реестром событий на бэкенде, и второе
 * его описание в JavaScript разъехалось бы на первом же новом типе поля.
 */
class NotificationRuleService
{
    public function __construct(
        private readonly NotificationEventRegistry $registry,
        private readonly ConditionValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): NotificationRule
    {
        return DB::transaction(function () use ($data, $actor): NotificationRule {
            $rule = NotificationRule::create($this->attributes($data) + [
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            $this->syncRecipients($rule, $data['recipients'] ?? []);

            return $rule->load('recipients');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(NotificationRule $rule, array $data, User $actor): NotificationRule
    {
        return DB::transaction(function () use ($rule, $data, $actor): NotificationRule {
            $attributes = $this->attributes($data);

            // У системного правила событие и признак системности неизменны:
            // оно воспроизводит поведение кода, и подмена события превратила бы
            // его в обычное правило с чужим ключом синхронизации.
            if ($rule->is_system) {
                unset($attributes['event_key'], $attributes['scope_type'],
                    $attributes['scope_user_id'], $attributes['scope_company_id'], $attributes['scope_manager_id']);
            }

            $rule->fill($attributes + ['updated_by_user_id' => $actor->id])->save();

            if (array_key_exists('recipients', $data)) {
                $this->syncRecipients($rule, $data['recipients']);
            }

            return $rule->load('recipients');
        });
    }

    /**
     * Копия системного правила с меньшим приоритетом и остановкой разбора.
     *
     * Так менеджер переопределяет поведение по умолчанию, не трогая само
     * системное правило: оно остаётся видимым и его всегда можно вернуть.
     */
    public function override(NotificationRule $system, User $actor): NotificationRule
    {
        return DB::transaction(function () use ($system, $actor): NotificationRule {
            $copy = NotificationRule::create([
                'name' => $system->name.' (переопределено)',
                'description' => 'Заменяет системное правило «'.$system->name.'».',
                'event_key' => $system->event_key,
                'scope_type' => $system->scope_type,
                'conditions' => $system->conditions,
                'priority' => max(1, $system->priority - 100),
                'stop_processing' => true,
                'is_active' => false,
                'channel' => $system->channel,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            foreach ($system->recipients as $recipient) {
                $copy->recipients()->create($recipient->only(['kind', 'contact_id', 'value', 'copy_type', 'is_fallback']));
            }

            return $copy->load('recipients');
        });
    }

    /**
     * Пересказ правила обычными словами — то, что менеджер читает под формой.
     */
    public function humanize(NotificationRule $rule): string
    {
        $event = $this->registry->label($rule->event_key);
        $scope = $this->scopeLabel($rule);
        $conditions = $this->humanizeConditions($rule->conditions, $rule->event_key);
        $recipients = $this->humanizeRecipients($rule);

        $sentence = 'Когда произошло «'.$event.'»';

        if ($scope !== null) {
            $sentence .= ' у '.$scope;
        }

        if ($conditions !== '') {
            $sentence .= ' и '.$conditions;
        }

        $sentence .= ', письмо уйдёт: '.$recipients.'.';

        if ($rule->stop_processing) {
            $sentence .= ' Правила ниже при этом не рассматриваются.';
        }

        return $sentence;
    }

    private function scopeLabel(NotificationRule $rule): ?string
    {
        return match ($rule->scope_type) {
            NotificationRule::SCOPE_USER => 'партнёра «'.($rule->scopeUser?->display_name ?? '—').'»',
            NotificationRule::SCOPE_COMPANY => 'контрагента «'.($rule->scopeCompany?->name ?? '—').'»',
            NotificationRule::SCOPE_MANAGER => 'клиентов менеджера «'.($rule->scopeManager?->name ?? '—').'»',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $conditions
     */
    private function humanizeConditions(?array $conditions, string $eventKey): string
    {
        if (blank($conditions)) {
            return '';
        }

        $fields = $this->registry->fieldsFor($eventKey);

        return $this->humanizeNode($conditions, $fields);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, \App\Notifications\Pulse\Support\FieldSpec>  $fields
     */
    private function humanizeNode(array $node, array $fields): string
    {
        foreach (['all' => ' и ', 'any' => ' или '] as $group => $glue) {
            if (isset($node[$group]) && is_array($node[$group])) {
                $parts = array_map(fn ($child) => is_array($child) ? $this->humanizeNode($child, $fields) : '', $node[$group]);

                return implode($glue, array_filter($parts));
            }
        }

        $op = (string) ($node['op'] ?? '=');
        $value = $node['value'] ?? null;

        if ($op === 'has_tag') {
            return 'есть метка «'.$value.'»';
        }

        if ($op === 'not_has_tag') {
            return 'нет метки «'.$value.'»';
        }

        $spec = $fields[(string) ($node['field'] ?? '')] ?? null;

        if ($spec === null) {
            return '';
        }

        $readable = $this->readableValue($spec, $value);

        return match ($op) {
            '=' => $spec->label.' = '.$readable,
            '!=' => $spec->label.' ≠ '.$readable,
            'in' => $spec->label.' — один из: '.$readable,
            'not_in' => $spec->label.' — кроме: '.$readable,
            '>' => $spec->label.' больше '.$readable,
            '>=' => $spec->label.' от '.$readable,
            '<' => $spec->label.' меньше '.$readable,
            '<=' => $spec->label.' до '.$readable,
            'between' => $spec->label.' в диапазоне '.$readable,
            'contains' => $spec->label.' содержит '.$readable,
            'not_contains' => $spec->label.' не содержит '.$readable,
            'is_empty' => $spec->label.' не заполнено',
            'not_empty' => $spec->label.' заполнено',
            default => $spec->label,
        };
    }

    private function readableValue(\App\Notifications\Pulse\Support\FieldSpec $spec, mixed $value): string
    {
        $map = [];

        foreach ($spec->options as $option) {
            $map[$option['value']] = $option['label'];
        }

        $render = function ($item) use ($map) {
            if (is_bool($item)) {
                return $item ? 'да' : 'нет';
            }

            return $map[(string) $item] ?? (string) $item;
        };

        if (is_array($value)) {
            return '«'.implode('», «', array_map($render, $value)).'»';
        }

        return '«'.$render($value).'»';
    }

    private function humanizeRecipients(NotificationRule $rule): string
    {
        $parts = $rule->recipients->map(function (NotificationRuleRecipient $recipient): string {
            $label = match ($recipient->kind) {
                NotificationRuleRecipient::KIND_CONTACT => $recipient->contact?->full_name ?? 'контакт',
                NotificationRuleRecipient::KIND_CONTACT_ROLE => 'все контакты роли «'
                    .(ClientContactRole::tryFrom((string) $recipient->value)?->label() ?? $recipient->value).'»',
                NotificationRuleRecipient::KIND_EMAIL => (string) $recipient->value,
                NotificationRuleRecipient::KIND_CLIENT_USER => 'клиент',
                NotificationRuleRecipient::KIND_COMPANY_EMAIL => 'почта контрагента',
                NotificationRuleRecipient::KIND_PERSONAL_MANAGER => 'персональный менеджер',
                NotificationRuleRecipient::KIND_CONFIG_LIST => 'резервный список',
                NotificationRuleRecipient::KIND_SUPPRESS => 'исключение: '.$recipient->value,
                default => $recipient->kind,
            };

            return $recipient->is_fallback ? $label.' (только если некому больше)' : $label;
        })->all();

        return $parts === [] ? 'никому — получатели не заданы' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $scopeType = $data['scope_type'] ?? NotificationRule::SCOPE_GLOBAL;

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'event_key' => $data['event_key'],
            'scope_type' => $scopeType,
            'scope_user_id' => $scopeType === NotificationRule::SCOPE_USER ? $data['scope_user_id'] : null,
            'scope_company_id' => $scopeType === NotificationRule::SCOPE_COMPANY ? $data['scope_company_id'] : null,
            'scope_manager_id' => $scopeType === NotificationRule::SCOPE_MANAGER ? $data['scope_manager_id'] : null,
            'conditions' => $data['conditions'] ?? null,
            'priority' => $data['priority'] ?? 100,
            'stop_processing' => (bool) ($data['stop_processing'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'channel' => $data['channel'] ?? 'email',
            'subject_override' => $data['subject_override'] ?? null,
            'throttle_seconds' => $data['throttle_seconds'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function syncRecipients(NotificationRule $rule, array $recipients): void
    {
        $rule->recipients()->delete();

        foreach ($recipients as $recipient) {
            $rule->recipients()->create([
                'kind' => $recipient['kind'],
                'contact_id' => $recipient['contact_id'] ?? null,
                'value' => $recipient['value'] ?? null,
                'copy_type' => $recipient['copy_type'] ?? 'to',
                'is_fallback' => (bool) ($recipient['is_fallback'] ?? false),
            ]);
        }
    }
}
