<?php

namespace App\Services\Crm\Mail;

use App\Enums\Crm\CrmScope;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\CrmMailRuleHit;
use App\Models\User;
use App\Services\Crm\CrmEmailService;

/**
 * Правила-фильтры: сборка условий из формы, превью и человеческий пересказ.
 *
 * Форма плоская — список строк «поле → сравнение → значение» плюс переключатель
 * «все условия / любое из условий». Вложенных групп нет намеренно: в почтовом
 * фильтре их тоже нет, и именно этой простоты не хватало прошлому подходу.
 */
class MailRuleService
{
    public function __construct(
        private readonly CrmEmailService $emails,
        private readonly LetterMatcher $matcher,
        private readonly MailRuleEngine $engine,
    ) {}

    /**
     * Собрать дерево условий из плоского списка формы.
     *
     * @param  array<int, array<string, mixed>>  $conditions
     * @return array<string, mixed>|null
     */
    public function buildConditions(string $match, array $conditions): ?array
    {
        $leaves = [];

        foreach ($conditions as $condition) {
            $op = (string) ($condition['op'] ?? '');
            $field = (string) ($condition['field'] ?? '');

            if ($field === '' || $op === '') {
                continue;
            }

            $leaf = ['field' => $field, 'op' => $op];

            if (! in_array($op, MailFieldCatalog::unaryOperators(), true)) {
                $leaf['value'] = $condition['value'] ?? null;
            }

            $leaves[] = $leaf;
        }

        if ($leaves === []) {
            return null;
        }

        return [$match === 'any' ? 'any' : 'all' => $leaves];
    }

    /**
     * Разобрать дерево обратно в плоскую форму.
     *
     * @param  array<string, mixed>|null  $conditions
     * @return array{match: string, conditions: array<int, array<string, mixed>>}
     */
    public function toForm(?array $conditions): array
    {
        $match = isset($conditions['any']) ? 'any' : 'all';
        $leaves = (array) ($conditions[$match] ?? []);

        return [
            'match' => $match,
            'conditions' => array_values(array_map(fn ($leaf): array => [
                'field' => (string) ($leaf['field'] ?? 'tag'),
                'op' => (string) ($leaf['op'] ?? 'has_tag'),
                'value' => $leaf['value'] ?? '',
            ], array_filter($leaves, 'is_array'))),
        ];
    }

    /**
     * Пересказ условий по-русски — для списка правил.
     *
     * @param  array<string, mixed>|null  $conditions
     */
    public function describe(?array $conditions): string
    {
        $form = $this->toForm($conditions);

        if ($form['conditions'] === []) {
            return 'Ловит все письма';
        }

        $labels = $this->fieldLabels();
        $operators = $this->operatorLabels();

        $parts = array_map(function (array $leaf) use ($labels, $operators): string {
            $field = $labels[$leaf['field']] ?? $leaf['field'];
            $op = $operators[$leaf['op']] ?? $leaf['op'];
            $value = is_array($leaf['value']) ? implode(', ', $leaf['value']) : (string) $leaf['value'];

            return trim($field.' '.$op.' '.$value);
        }, $form['conditions']);

        return implode($form['match'] === 'any' ? ' или ' : ' и ', $parts);
    }

    /**
     * Реальные письма из потока, которые подошли бы под условия.
     *
     * Главное в форме правила. Абстрактный список получателей ничего не говорит
     * менеджеру о том, верно ли набрано условие; конкретные строки — говорят.
     *
     * @param  array<string, mixed>|null  $conditions
     * @return array{total: int, scanned: int, letters: array<int, array<string, mixed>>}
     */
    public function preview(User $actor, ?array $conditions, int $limit = 10): array
    {
        $window = (int) config('mail_stream.preview_window', 300);

        $letters = $this->emails->visibleTo($actor, CrmScope::DEPARTMENT)
            ->with(['author:id,name', 'related', 'client.crmProfile'])
            ->latest('id')
            ->limit($window)
            ->get();

        $probe = new CrmMailRule(['conditions' => $conditions]);

        $matched = $letters->filter(fn (CrmEmail $letter): bool => $this->matcher->matches($probe, $letter));

        return [
            'total' => $matched->count(),
            'scanned' => $letters->count(),
            'letters' => $matched->take($limit)
                ->map(fn (CrmEmail $letter): array => [
                    'id' => (int) $letter->getKey(),
                    'subject' => $letter->subject,
                    'folder' => $letter->status->label(),
                    'origin_label' => $letter->isSystem() ? 'Система' : 'Менеджер',
                    'client' => $letter->client?->display_name,
                    'created_at_label' => $letter->created_at?->format('d.m.Y H:i'),
                    'tags' => $letter->tagList(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Строка правила для списка.
     *
     * @return array<string, mixed>
     */
    public function payload(CrmMailRule $rule): array
    {
        $form = $this->toForm($rule->conditions);

        return [
            'id' => (int) $rule->getKey(),
            'name' => $rule->name,
            'match' => $form['match'],
            'conditions' => $form['conditions'],
            'conditions_text' => $this->describe($rule->conditions),
            'recipients' => (array) $rule->recipients,
            'cc' => (array) ($rule->cc ?? []),
            'auto_send' => (bool) $rule->auto_send,
            'is_active' => (bool) $rule->is_active,
            'throttle_minutes' => $rule->throttle_minutes,
            'matched_count' => (int) $rule->matched_count,
            'matched_last_month' => $this->matchesLastMonth($rule),
            'last_matched_at_label' => $rule->last_matched_at?->format('d.m.Y H:i'),
            'author' => $rule->author?->name,
        ];
    }

    /**
     * Сколько писем правило поймало за месяц. Ноль — почти всегда опечатка
     * в условии, и увидеть это менеджер должен сам.
     */
    private function matchesLastMonth(CrmMailRule $rule): int
    {
        return CrmMailRuleHit::query()
            ->where('rule_id', $rule->getKey())
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
    }

    /**
     * Подобрать письма, которые уже лежат «Мимо фильтров».
     */
    public function reapply(CrmMailRule $rule): int
    {
        if (! $rule->is_active) {
            return 0;
        }

        return $this->engine->reapplyToUnmatched($rule);
    }

    /**
     * @return array<string, string>
     */
    private function fieldLabels(): array
    {
        $labels = [];

        foreach (MailFieldCatalog::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $labels[$field['value']] = $field['label'];
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function operatorLabels(): array
    {
        $labels = [];

        foreach (MailFieldCatalog::operators() as $list) {
            foreach ($list as $item) {
                $labels[$item['value']] = $item['label'];
            }
        }

        return $labels;
    }
}
