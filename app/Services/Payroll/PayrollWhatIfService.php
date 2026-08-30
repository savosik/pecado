<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\InvoiceInput;
use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\Dto\PlannedClientInput;

/**
 * «Сколько будет, если…» — кривые дохода для графиков.
 *
 * Каждая точка считается тем же калькулятором на гипотетических входах, поэтому
 * график и цифра на экране не могут разойтись. Это ответ на вопрос менеджера
 * «дожму план — сколько получу», заданный картинкой, а не формулой.
 */
class PayrollWhatIfService
{
    /** Шаг кривой по выручке, доля плана. */
    private const REVENUE_STEP = 0.05;

    /** Докуда рисуем кривую выручки сверх потолка — чтобы полка была видна. */
    private const REVENUE_TAIL = 0.15;

    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function curves(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        return [
            'revenue' => $this->revenueCurve($params, $inputs, $current),
            'clients' => $this->clientsCurve($params, $inputs, $current),
            'penalty' => $this->penaltyScale($params, $inputs, $current),
        ];
    }

    /**
     * Доход как функция выполнения плана по выручке: где я сейчас, где полка потолка.
     *
     * @return array<string, mixed>
     */
    private function revenueCurve(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $plan = $inputs->plan;

        if ($plan === null || $plan <= 0) {
            return ['points' => [], 'current' => null, 'target' => null, 'cap' => null];
        }

        $kpi = $current->component('kpi_bonus');
        $cap = (float) ($kpi->meta['cap'] ?? 2.0);
        $multiplier = max(0.01, (float) ($kpi->meta['multiplier'] ?? 1.0));

        // Кривая ведётся до полки потолка: выполнение упирается в cap при
        // выручке cap / множитель, дальше доход не растёт — это и надо показать.
        $maxShare = min(3.0, $cap / $multiplier + self::REVENUE_TAIL);

        $points = [];
        for ($share = 0.0; $share <= $maxShare + 1e-9; $share += self::REVENUE_STEP) {
            $points[] = $this->pointAtRevenue($params, $inputs, round($share, 4), $plan);
        }

        $currentShare = round($inputs->revenue / $plan, 4);
        $capShare = round($cap / $multiplier, 4);

        return [
            'points' => $points,
            'current' => ['share' => $currentShare, 'total' => $current->total, 'revenue' => $inputs->revenue],
            'target' => $this->pointAtRevenue($params, $inputs, 1.0, $plan),
            'cap' => $capShare <= $maxShare ? $this->pointAtRevenue($params, $inputs, $capShare, $plan) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pointAtRevenue(EffectiveParams $params, PayrollInputs $inputs, float $share, float $plan): array
    {
        $revenue = round($plan * $share, 2);
        $breakdown = $this->calculator->calculate($params, $inputs->with(['revenue' => $revenue]));

        return [
            'share' => $share,
            'revenue' => $revenue,
            'total' => $breakdown->total,
            'kpi' => $breakdown->amountOf('kpi_bonus'),
        ];
    }

    /**
     * Доход как ступенчатая функция числа активных клиентов: видно, где следующая ступень.
     *
     * @return array<string, mixed>
     */
    private function clientsCurve(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $planned = count($inputs->plannedClients);

        if ($planned === 0) {
            return ['points' => [], 'current' => null, 'steps' => []];
        }

        $active = count($inputs->activeClients());
        $points = [];

        for ($n = 0; $n <= $planned; $n++) {
            $breakdown = $this->calculator->calculate($params, $inputs->with([
                'planned_clients' => $this->withActive($inputs, $n),
            ]));
            $factor = $this->factorMeta($breakdown);

            $points[] = [
                'active' => $n,
                'share' => $planned > 0 ? round($n / $planned, 4) : null,
                'multiplier' => (float) ($factor['multiplier'] ?? 1.0),
                'total' => $breakdown->total,
                'kpi' => $breakdown->amountOf('kpi_bonus'),
            ];
        }

        // Ступени лестницы в терминах «сколько клиентов»: подписи порогов на графике.
        $ladder = (array) ($this->factorMeta($current)['ladder'] ?? []);
        $steps = [];
        foreach ($ladder as $step) {
            $need = (int) ceil((float) $step['from_share'] * $planned - 1e-9);
            if ($need > $planned) {
                continue;
            }
            $steps[] = [
                'active' => $need,
                'from_share' => (float) $step['from_share'],
                'multiplier' => (float) $step['multiplier'],
                'reached' => $active >= $need,
            ];
        }

        return [
            'points' => $points,
            'current' => ['active' => $active, 'planned' => $planned, 'total' => $current->total],
            'steps' => $steps,
        ];
    }

    /**
     * Шкала штрафа: сколько было бы без него, сколько сейчас, сколько при худшем исходе.
     *
     * @return array<string, mixed>
     */
    private function penaltyScale(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $kpi = $current->component('kpi_bonus');
        $penalty = (float) ($kpi->meta['penalty'] ?? 0.0);

        $clean = $this->calculator->calculate($params, $inputs->with(['invoices' => []]))->total;

        $atRisk = $inputs->atRiskInvoices;
        $worst = $current->total;

        if ($atRisk !== []) {
            $tiers = (array) ($params->for('kpi_bonus')['discipline_penalty']['tiers'] ?? []);
            $worstDays = $tiers === [] ? null : (int) ($tiers[count($tiers) - 1]['from_days'] ?? 8);

            if ($worstDays !== null) {
                $simulated = array_map(
                    fn (InvoiceInput $i): array => $i->with([
                        'settled_on' => $inputs->month,
                        'delay_working_days' => $worstDays,
                        'delay_calendar_days' => $worstDays,
                        'source' => 'forecast',
                        'payment_status' => 'paid',
                    ])->toArray(),
                    $atRisk,
                );

                $worst = $this->calculator->calculate($params, $inputs->with([
                    'invoices' => array_merge(array_map(fn (InvoiceInput $i): array => $i->toArray(), $inputs->invoices), $simulated),
                ]))->total;
            }
        }

        return [
            'clean' => $clean,                                   // если бы все платили в срок
            'current' => $current->total,
            'worst' => $worst,                                   // если неоплаченные закроются с задержкой
            'penalty' => $penalty,
            'lost' => round($clean - $current->total, 2),
            'at_risk_count' => count($atRisk),
            'at_risk_amount' => round(array_sum(array_map(fn (InvoiceInput $i): float => $i->amount, $atRisk)), 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withActive(PayrollInputs $inputs, int $count): array
    {
        $rows = $inputs->plannedClients;
        usort($rows, fn (PlannedClientInput $a, PlannedClientInput $b): int => ($b->plan ?? 0) <=> ($a->plan ?? 0));

        $result = [];
        foreach ($rows as $index => $client) {
            $result[] = (new PlannedClientInput(
                $client->id,
                $client->name,
                $client->plan,
                $index < $count ? max(1.0, (float) ($client->plan ?? 1.0)) : 0.0,
            ))->toArray();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function factorMeta(PayrollBreakdown $breakdown): array
    {
        foreach ($breakdown->component('kpi_bonus')?->children ?? [] as $child) {
            if ($child->key === 'active_clients') {
                return $child->meta;
            }
        }

        return [];
    }
}
