<?php

namespace App\Services\Notifications\Pulse;

use App\Enums\ClientContactRole;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Notifications\ClientContactService;
use Illuminate\Support\Facades\DB;

/**
 * Применение пресета правил к контрагенту.
 *
 * Конструктора мало: об это уже разбилась клиентская подписка — механизм
 * работал, но собирать правила руками никто не стал. Настройка типового
 * контрагента должна занимать одну кнопку.
 *
 * Недостающие роли подсвечиваются, а не пропускаются молча: иначе менеджер
 * будет уверен, что настроил, а письмо не уйдёт.
 */
class PresetApplier
{
    public function __construct(
        private readonly ClientContactService $contacts,
        private readonly NotificationEventRegistry $registry,
    ) {}

    /**
     * Каталог пресетов для интерфейса.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        $result = [];

        foreach ((array) config('notification_pulse.presets', []) as $key => $preset) {
            $result[] = [
                'key' => $key,
                'label' => $preset['label'],
                'description' => $preset['description'] ?? '',
                'rules_count' => count($preset['rules'] ?? []),
                'roles' => $this->rolesUsedBy($preset),
            ];
        }

        return $result;
    }

    /**
     * Что получится, если применить пресет к этому контрагенту.
     *
     * Считается до выполнения: менеджер должен видеть, какие правила
     * создадутся и каких контактов не хватает.
     *
     * @return array{will_create: array<int, array<string, mixed>>, missing: array<int, array<string, mixed>>, already: int}
     */
    public function preview(string $presetKey, Company $company): array
    {
        $preset = $this->preset($presetKey);

        if ($preset === null) {
            return ['will_create' => [], 'missing' => [], 'already' => 0];
        }

        $willCreate = [];
        $missing = [];
        $already = 0;

        foreach ($preset['rules'] as $definition) {
            if ($this->alreadyApplied($presetKey, $company, $definition)) {
                $already++;

                continue;
            }

            $resolved = $this->resolveRecipients($definition, $company);

            if ($resolved['missing'] !== []) {
                foreach ($resolved['missing'] as $role) {
                    $missing[] = [
                        'rule' => $definition['name'],
                        'role' => $role,
                        'role_label' => ClientContactRole::tryFrom($role)?->label() ?? $role,
                    ];
                }

                // Правило без единого адресата создавать бессмысленно —
                // оно будет молчать, а менеджер решит, что настроил.
                if ($resolved['recipients'] === []) {
                    continue;
                }
            }

            $willCreate[] = [
                'name' => $definition['name'],
                'event_label' => $this->registry->label($definition['event_key']),
                'recipients' => $resolved['labels'],
            ];
        }

        return ['will_create' => $willCreate, 'missing' => $missing, 'already' => $already];
    }

    /**
     * Применить пресет. Идемпотентно: повтор не плодит правила.
     *
     * @return array{created: int, skipped: int, missing: array<int, array<string, mixed>>}
     */
    public function apply(string $presetKey, Company $company, User $actor): array
    {
        $preset = $this->preset($presetKey);

        if ($preset === null) {
            return ['created' => 0, 'skipped' => 0, 'missing' => []];
        }

        $created = 0;
        $skipped = 0;
        $missing = [];

        foreach ($preset['rules'] as $definition) {
            if ($this->alreadyApplied($presetKey, $company, $definition)) {
                $skipped++;

                continue;
            }

            $resolved = $this->resolveRecipients($definition, $company);

            foreach ($resolved['missing'] as $role) {
                $missing[] = [
                    'rule' => $definition['name'],
                    'role' => $role,
                    'role_label' => ClientContactRole::tryFrom($role)?->label() ?? $role,
                ];
            }

            if ($resolved['recipients'] === []) {
                $skipped++;

                continue;
            }

            $this->createRule($presetKey, $company, $definition, $resolved['recipients'], $actor);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'missing' => $missing];
    }

    /**
     * Покрытие: сколько контрагентов реально получит письма по правилам-политикам.
     *
     * Главный риск домена — пустая адресная книга: движок работает, а слать
     * некому, и выясняется это через жалобу клиента. Цифра делает дыру видимой.
     *
     * @return array<int, array<string, mixed>>
     */
    public function coverage(User $actor): array
    {
        $policies = NotificationRule::query()
            ->with('recipients')
            ->where('is_active', true)
            ->whereIn('scope_type', [NotificationRule::SCOPE_GLOBAL, NotificationRule::SCOPE_MANAGER])
            ->get();

        $totalCompanies = Company::query()->visibleInCrm($actor)->count();
        $rows = [];

        foreach ($policies as $rule) {
            $roles = $rule->recipients
                ->where('kind', 'contact_role')
                ->pluck('value')
                ->filter()
                ->values();

            // Правило без ролей адресует конкретных людей или клиента —
            // покрытие для него считать нечего.
            if ($roles->isEmpty()) {
                continue;
            }

            $covered = Company::query()
                ->visibleInCrm($actor)
                ->whereHas('contacts', fn ($q) => $q->deliverable()->whereIn('role', $roles))
                ->count();

            $rows[] = [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'event_label' => $this->registry->label($rule->event_key),
                'roles' => $roles->map(fn ($r) => ClientContactRole::tryFrom($r)?->label() ?? $r)->all(),
                'covered' => $covered,
                'total' => $totalCompanies,
                'uncovered' => max(0, $totalCompanies - $covered),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function preset(string $key): ?array
    {
        return config('notification_pulse.presets.'.$key);
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array<int, string>
     */
    private function rolesUsedBy(array $preset): array
    {
        $roles = [];

        foreach ($preset['rules'] ?? [] as $rule) {
            foreach ($rule['recipients'] ?? [] as $recipient) {
                if (($recipient['kind'] ?? '') === 'contact_role' && filled($recipient['value'] ?? null)) {
                    $roles[] = ClientContactRole::tryFrom($recipient['value'])?->label() ?? $recipient['value'];
                }
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * Раскрыть получателей пресета в применимые к этому контрагенту.
     *
     * @param  array<string, mixed>  $definition
     * @return array{recipients: array<int, array<string, mixed>>, missing: array<int, string>, labels: array<int, string>}
     */
    private function resolveRecipients(array $definition, Company $company): array
    {
        $recipients = [];
        $missing = [];
        $labels = [];

        foreach ($definition['recipients'] as $recipient) {
            if (($recipient['kind'] ?? '') !== 'contact_role') {
                $recipients[] = $recipient;
                $labels[] = $recipient['kind'] === 'client_user' ? 'клиент' : $recipient['kind'];

                continue;
            }

            $role = (string) $recipient['value'];
            $found = $this->contacts->deliverableByRole($company->user_id, $company->id, $role);

            if ($found->isEmpty()) {
                $missing[] = $role;

                continue;
            }

            $recipients[] = $recipient;
            $labels[] = ClientContactRole::tryFrom($role)?->label().': '.$found->pluck('full_name')->implode(', ');
        }

        return ['recipients' => $recipients, 'missing' => $missing, 'labels' => $labels];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function alreadyApplied(string $presetKey, Company $company, array $definition): bool
    {
        return NotificationRule::query()
            ->where('preset_key', $presetKey)
            ->where('scope_company_id', $company->id)
            ->where('event_key', $definition['event_key'])
            ->where('priority', $definition['priority'])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function createRule(
        string $presetKey,
        Company $company,
        array $definition,
        array $recipients,
        User $actor,
    ): void {
        DB::transaction(function () use ($presetKey, $company, $definition, $recipients, $actor): void {
            $rule = NotificationRule::create([
                'name' => $definition['name'],
                'description' => 'Создано из пресета. Можно править как обычное правило.',
                'event_key' => $definition['event_key'],
                'scope_type' => NotificationRule::SCOPE_COMPANY,
                'scope_company_id' => $company->id,
                'conditions' => $definition['conditions'] ?? null,
                'priority' => $definition['priority'],
                'stop_processing' => (bool) ($definition['stop_processing'] ?? false),
                'attach_documents' => (bool) ($definition['attach_documents'] ?? false),
                'throttle_seconds' => $definition['throttle_seconds'] ?? null,
                'is_active' => true,
                'preset_key' => $presetKey,
                'channel' => 'email',
                'digest' => 'none',
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            foreach ($recipients as $recipient) {
                $rule->recipients()->create([
                    'kind' => $recipient['kind'],
                    'value' => $recipient['value'] ?? null,
                    'copy_type' => 'to',
                ]);
            }
        });
    }
}
