<?php

namespace App\Services\Motivation;

use App\Services\Motivation\Dto\MotivationInputs;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollBreakdown;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\Support\Money;

/**
 * Рычаги и прогноз «Моего месяца» (карточка mot-26).
 *
 * Каждая цена в рублях получена изоляцией: доход с гипотетическим входом минус
 * доход с фактическим, тем же калькулятором. Своей арифметики здесь нет —
 * иначе совет «плюс 8 420 ₽» и настоящий расчёт рано или поздно разойдутся,
 * и работник перестанет верить обоим.
 *
 * Порядок рычагов задаёт сервер: сверху то, что достижимее и даёт больше.
 */
class MotivationAdvisor
{
    /** Шаг «дожать план», ₽ — размер обычной недельной отгрузки базы. */
    private const PLAN_STEP = 500_000.0;

    /** Шаг для фокус-товаров, ₽ — одна показательная сделка по перечню. */
    private const FOCUS_STEP = 100_000.0;

    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * @return list<array{key: string, title: string, value: float, hint: string, href: string}>
     */
    public function levers(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current): array
    {
        $motivation = $inputs->motivation;

        if ($motivation === null) {
            return [];
        }

        $levers = [];
        $variable = $current->component('motivation_variable');
        $threshold = (float) ($variable?->meta['threshold'] ?? 0);

        // 1. Дожать план: до порога оплата не начинается, поэтому первый шаг —
        //    это «до порога и ещё немного», а не абстрактные полмиллиона.
        $gap = max(0.0, $threshold - $motivation->baseRevenue);
        $step = $gap > 0 ? $gap + self::PLAN_STEP : self::PLAN_STEP;
        $gain = $this->gain($params, $inputs, $current, ['base_revenue' => $motivation->baseRevenue + $step]);

        if ($gain > 0) {
            $levers[] = [
                'key' => 'plan',
                'title' => $gap > 0
                    ? sprintf('Дойти до порога оплаты и отгрузить ещё %s', Money::rub(self::PLAN_STEP))
                    : sprintf('Отгрузить базе ещё %s', Money::rub(self::PLAN_STEP)),
                'value' => $gain,
                'hint' => $gap > 0
                    ? sprintf('До порога не хватает %s — ниже него П1 не начисляется', Money::rub($gap))
                    : sprintf('Каждые %s сверх порога — плюс %s', Money::rub(self::PLAN_STEP), Money::rub($gain)),
                'href' => '/crm/motivation/base',
            ];
        }

        // 2. Собрать долг с одного партнёра — самого дорогого по вкладу в вычет.
        $debt = $this->costliestDebtor($motivation);

        if ($debt !== null) {
            $without = array_values(array_filter(
                $motivation->overdueRows,
                fn (array $row): bool => (int) ($row['partner_id'] ?? 0) !== $debt['partner_id'],
            ));
            $integral = array_sum(array_map(fn (array $row): float => (float) ($row['integral'] ?? 0), $without));

            $gain = $this->gain($params, $inputs, $current, [
                'overdue_integral' => Money::round($integral),
                'overdue_rows' => $without,
            ]);

            if ($gain > 0) {
                $levers[] = [
                    'key' => 'debt',
                    'title' => sprintf('Собрать долг с %s', $debt['partner_name'] !== '' ? $debt['partner_name'] : 'партнёра'),
                    'value' => $gain,
                    'hint' => sprintf(
                        '%d %s на %s — столько вычет стоил вам в этом месяце',
                        $debt['documents'],
                        $this->plural($debt['documents'], 'накладная', 'накладные', 'накладных'),
                        Money::rub($debt['amount']),
                    ),
                    'href' => '/crm/motivation/debts?partner='.$debt['partner_id'],
                ];
            }
        }

        // 3. Предложить фокус-позицию.
        $gain = $this->gain($params, $inputs, $current, ['focus_revenue' => $motivation->focusRevenue + self::FOCUS_STEP]);

        if ($gain > 0) {
            $levers[] = [
                'key' => 'focus',
                'title' => sprintf('Продать фокус-товаров на %s', Money::rub(self::FOCUS_STEP)),
                'value' => $gain,
                'hint' => 'Надбавка начисляется с первого рубля и не зависит от порога оплаты',
                'href' => '/crm/motivation/focus',
            ];
        }

        usort($levers, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $levers;
    }

    /**
     * Вилка на конец месяца: как есть, как идёт, как пойдёт, если сделать рычаги.
     *
     * @param  list<array{value: float}>  $levers
     * @return array{low: float, expected: float, high: float, days_passed: int, days_total: int}|null
     */
    public function forecast(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current, array $levers): ?array
    {
        $motivation = $inputs->motivation;
        $passed = max(0, (int) ($inputs->workingDays['passed'] ?? 0));
        $total = max(1, (int) ($inputs->workingDays['total'] ?? 0));

        if ($motivation === null || $passed === 0 || $passed >= $total) {
            return null;
        }

        // «Как идёт»: отгрузки и долги растут тем же темпом до конца месяца.
        $rate = $total / $passed;
        $expected = $this->calculator->calculate($params, $this->with($inputs, [
            'base_revenue' => Money::round($motivation->baseRevenue * $rate),
            'new_partners_revenue' => Money::round($motivation->newPartnersRevenue * $rate),
            'focus_revenue' => Money::round($motivation->focusRevenue * $rate),
            'overdue_integral' => Money::round($motivation->overdueIntegral * $rate),
        ]))->total;

        $leverGain = array_sum(array_map(fn (array $lever): float => (float) $lever['value'], $levers));

        return [
            'low' => Money::round($current->total),
            'expected' => Money::round($expected),
            'high' => Money::round($expected + $leverGain),
            'days_passed' => $passed,
            'days_total' => $total,
        ];
    }

    /**
     * Сколько даст изменение входов — тем же расчётом.
     *
     * @param  array<string, mixed>  $changes  ключи как в MotivationInputs::toArray()
     */
    private function gain(EffectiveParams $params, PayrollInputs $inputs, PayrollBreakdown $current, array $changes): float
    {
        $alternative = $this->calculator->calculate($params, $this->with($inputs, $changes));

        return Money::round($alternative->total - $current->total);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(PayrollInputs $inputs, array $changes): PayrollInputs
    {
        $motivation = array_replace(($inputs->motivation ?? new MotivationInputs)->toArray(), $changes);

        return $inputs->with(['motivation' => $motivation]);
    }

    /**
     * Партнёр с наибольшим вкладом в базу вычета.
     *
     * @return array{partner_id: int, partner_name: string, documents: int, amount: float, integral: float}|null
     */
    private function costliestDebtor(MotivationInputs $motivation): ?array
    {
        $byPartner = [];

        foreach ($motivation->overdueRows as $row) {
            $partnerId = (int) ($row['partner_id'] ?? 0);

            if ($partnerId === 0) {
                continue;
            }

            $byPartner[$partnerId] ??= [
                'partner_id' => $partnerId,
                'partner_name' => (string) ($row['partner_name'] ?? ''),
                'documents' => 0,
                'amount' => 0.0,
                'integral' => 0.0,
            ];
            $byPartner[$partnerId]['documents']++;
            $byPartner[$partnerId]['amount'] += (float) ($row['balance_end'] ?? $row['amount'] ?? 0);
            $byPartner[$partnerId]['integral'] += (float) ($row['integral'] ?? 0);
        }

        if ($byPartner === []) {
            return null;
        }

        usort($byPartner, fn (array $a, array $b): int => $b['integral'] <=> $a['integral']);

        return $byPartner[0];
    }

    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }
}
