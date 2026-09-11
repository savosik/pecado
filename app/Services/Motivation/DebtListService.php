<?php

namespace App\Services\Motivation;

use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\DebtState;
use App\Models\PayrollCalculation;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Support\Money;

/**
 * «Долги: во что они обходятся» (карточка mot-28, контракт 04 § 4).
 *
 * Строится из снимка расчёта, а не из живого регистра: у утверждённого месяца
 * таблица обязана показывать состав вычета, который был начислен, а у черновика
 * снимок и так свежий. Ставка — из параметров снимка, чтобы «стоит вам в день»
 * совпадало с тем, чем считали.
 *
 * Двухуровневый список: партнёр сверху, накладные внутри. Двести накладных
 * подряд бессмысленны — вопрос экрана «какой долг стоит дороже всего», и это
 * вопрос о партнёре.
 */
class DebtListService
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollCalculation $calculation): array
    {
        $inputs = PayrollInputs::fromArray((array) $calculation->inputs);
        $motivation = $inputs->motivation;
        $params = EffectiveParams::fromArray((array) $calculation->params_effective);
        $rate = (float) ($params->for('motivation_variable')['rate_k1_per_day'] ?? 0);

        $rows = $motivation->overdueRows ?? [];
        $excluded = $motivation->overdueExcludedRows ?? [];
        $k1 = $this->k1($calculation);

        $partners = $this->groupByPartner($rows, $rate);
        $ids = array_map(fn (array $p): int => $p['id'], $partners);
        $levels = $this->levels($ids);
        $tasks = $this->nextTasks($ids);

        foreach ($partners as &$partner) {
            $partner['debt_level'] = $levels[$partner['id']] ?? null;
            $partner['next_task_due'] = $tasks[$partner['id']] ?? null;
        }
        unset($partner);

        usort($partners, fn (array $a, array $b): int => $b['deducted_this_month'] <=> $a['deducted_this_month']);

        return [
            'month' => $inputs->month,
            'frozen' => $calculation->isFrozen(),
            'rate_per_day' => $rate,
            'summary' => [
                'overdue_total' => Money::round(array_sum(array_column($partners, 'debt'))),
                'daily_cost' => Money::round(array_sum(array_column($partners, 'daily_cost'))),
                'deducted_this_month' => $k1,
                'partners_count' => count($partners),
                'invoices_count' => count($rows),
                'needs_review_count' => count(array_filter($rows, fn (array $r): bool => (bool) ($r['needs_review'] ?? false))),
                'excluded_count' => count($excluded),
            ],
            'partners' => $partners,
            'excluded' => array_map(fn (array $row): array => $this->excludedRow($row), $excluded),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupByPartner(array $rows, float $rate): array
    {
        $partners = [];

        foreach ($rows as $row) {
            $id = (int) ($row['partner_id'] ?? 0);
            $balance = (float) ($row['balance_end'] ?? 0);
            $integral = (float) ($row['integral'] ?? 0);
            $days = (int) ($row['days'] ?? 0);

            $partners[$id] ??= [
                'id' => $id,
                'name' => (string) ($row['partner_name'] ?? ''),
                'debt' => 0.0,
                'oldest_days' => 0,
                'daily_cost' => 0.0,
                'deducted_this_month' => 0.0,
                'integral' => 0.0,
                'needs_review_count' => 0,
                'invoices' => [],
            ];

            $partners[$id]['debt'] += $balance;
            $partners[$id]['integral'] += $integral;
            $partners[$id]['oldest_days'] = max($partners[$id]['oldest_days'], $days);
            $partners[$id]['needs_review_count'] += (bool) ($row['needs_review'] ?? false) ? 1 : 0;
            $partners[$id]['invoices'][] = [
                'id' => (int) ($row['invoice_id'] ?? 0),
                'shipment_id' => (int) ($row['shipment_id'] ?? 0),
                'number' => (string) ($row['number'] ?? ''),
                'date' => $row['shipped_on'] ?? null,
                'amount' => (float) ($row['amount'] ?? 0),
                'balance' => $balance,
                'due_on' => $row['due_on'] ?? null,
                'grace_ends_on' => $row['grace_ends_on'] ?? null,
                'overdue_days' => $days,
                'daily_cost' => Money::round($balance * $rate),
                'deducted_this_month' => Money::round($integral * $rate),
                'settled_on' => $row['settled_on'] ?? null,
                'needs_review' => (bool) ($row['needs_review'] ?? false),
            ];
        }

        foreach ($partners as &$partner) {
            usort($partner['invoices'], fn (array $a, array $b): int => $b['deducted_this_month'] <=> $a['deducted_this_month']);
            $partner['debt'] = Money::round($partner['debt']);
            // Открытый долг стоит в день столько, сколько остаток × ставка; закрытые
            // в этом месяце накладные в дневную цену уже не входят.
            $partner['daily_cost'] = Money::round($partner['debt'] * $rate);
            $partner['deducted_this_month'] = Money::round($partner['integral'] * $rate);
            unset($partner['integral']);
        }
        unset($partner);

        return array_values($partners);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function excludedRow(array $row): array
    {
        return [
            'invoice_id' => (int) ($row['invoice_id'] ?? 0),
            'number' => (string) ($row['number'] ?? ''),
            'partner_id' => (int) ($row['partner_id'] ?? 0),
            'partner_name' => (string) ($row['partner_name'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'balance' => (float) ($row['balance_end'] ?? 0),
            'due_on' => $row['due_on'] ?? null,
            'excluded_days' => (int) ($row['excluded_days'] ?? 0),
            'counted_days' => (int) ($row['days'] ?? 0),
            'reason' => (string) ($row['exclusion_reason'] ?? ''),
            'reason_label' => $this->reasonLabel((string) ($row['exclusion_reason'] ?? '')),
        ];
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'written_off' => 'списан как безнадёжный',
            'legal' => 'передан в претензионную работу',
            'disputed' => 'оспаривается партнёром',
            default => 'выведен из расчёта руководителем',
        };
    }

    private function k1(PayrollCalculation $calculation): float
    {
        foreach ((array) data_get($calculation->breakdown, 'components', []) as $component) {
            if (is_array($component) && ($component['key'] ?? null) === 'motivation_variable') {
                return (float) ($component['meta']['k1'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function levels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $levels = [];

        foreach (DebtState::query()->partners()->whereIn('user_id', $ids)->get(['user_id', 'level']) as $state) {
            $levels[(int) $state->user_id] = $state->level->value;
        }

        return $levels;
    }

    /**
     * Ближайшая открытая задача по партнёру — то, что заменяет «обещал заплатить».
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function nextTasks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = CrmTask::query()
            ->whereIn('client_user_id', $ids)
            ->whereIn('status', [TaskStatus::OPEN->value, TaskStatus::IN_PROGRESS->value])
            ->whereNotNull('due_at')
            ->selectRaw('client_user_id, MIN(due_at) AS due')
            ->groupBy('client_user_id')
            ->get();

        $tasks = [];

        foreach ($rows as $row) {
            $tasks[(int) $row->getAttribute('client_user_id')] = (string) $row->getAttribute('due');
        }

        return $tasks;
    }
}
