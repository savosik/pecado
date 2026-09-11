<?php

namespace App\Services\Motivation;

use App\Models\CrmSalesPlan;
use App\Models\Motivation\MotivationPlanOrder;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Откуда мой план» (карточка mot-30): расчёт по шагам, что вошло в медиану, три месяца квартала.
 *
 * Если план квартала утверждён приказом, показываются его значения и его
 * обоснование; иначе — расчётное значение с пометкой «предварительно».
 * Прогноза «как падение продаж уменьшит план следующего квартала» нет
 * намеренно: он прямо подсказывает провалить квартал ради лёгкого плана.
 */
class PlanReferenceService
{
    public function __construct(private readonly PlanCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $managerId, CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $quarter = $period->startOfQuarter();

        $order = MotivationPlanOrder::query()
            ->forQuarter($quarter)
            ->where('personal_manager_id', $managerId)
            ->where('status', MotivationPlanOrder::STATUS_APPROVED)
            ->orderByDesc('version')
            ->first();

        $calculated = $this->calculator->calculate($managerId, $quarter);

        $median = $order === null ? $calculated['median_per_day'] : (float) $order->median_per_day;
        $growth = $order === null ? $calculated['growth_rate'] : (float) $order->growth_rate;
        $seasonal = $order === null ? $calculated['seasonal'] : (array) $order->seasonal;
        $workingDays = $order === null ? $calculated['working_days'] : (array) $order->working_days;
        $values = $order === null ? $calculated['values'] : (array) $order->values;
        $carry = $order === null ? $calculated['overperformance_carry'] : (float) ($order->overperformance_carry ?? 0);

        $months = [];
        foreach ($values as $key => $value) {
            $months[] = [
                'month' => (string) $key,
                'working_days' => (int) ($workingDays[$key] ?? 0),
                'seasonal' => (float) ($seasonal[$key] ?? 1.0),
                'plan' => Money::round((float) $value),
                'current_plan' => $this->currentPlan($managerId, CarbonImmutable::parse((string) $key)),
                'is_this_month' => (string) $key === $period->toDateString(),
            ];
        }

        $thisMonth = $months[array_search(true, array_column($months, 'is_this_month'), true) ?: 0] ?? null;

        return [
            'month' => $period->toDateString(),
            'quarter' => $quarter->toDateString(),
            'approved' => $order !== null,
            'order' => $order === null ? null : [
                'version' => (int) $order->version,
                'approved_at' => $order->approved_at?->toIso8601String(),
                'decline_limited' => (bool) $order->decline_limited,
                'comment' => $order->comment,
            ],
            'steps' => [
                ['key' => 'median', 'label' => 'Медиана отгрузок за отработанный рабочий день', 'value' => Money::round($median), 'unit' => 'rub',
                    'note' => sprintf('По %d рабочим дням с %s по %s; исключено дней отсутствия — %d', $calculated['sample']['days'], $this->ru($calculated['sample']['from']), $this->ru($calculated['sample']['to']), $calculated['sample']['excluded_days'])],
                ['key' => 'days', 'label' => 'Рабочих дней в месяце', 'value' => $thisMonth['working_days'] ?? null, 'unit' => 'days',
                    'note' => 'По производственному календарю; дни отпуска уменьшают план уже в расчётном периоде'],
                ['key' => 'seasonal', 'label' => 'Сезонный коэффициент', 'value' => $thisMonth['seasonal'] ?? 1.0, 'unit' => 'factor',
                    'note' => 'Устанавливается приказом на год; единица — сезонность не учитывается'],
                ['key' => 'growth', 'label' => 'Целевой прирост', 'value' => $growth, 'unit' => 'share',
                    'note' => 'Устанавливается приказом на год'],
                ['key' => 'carry', 'label' => 'Половина перевыполнения прошлого квартала', 'value' => Money::round($carry), 'unit' => 'rub',
                    'note' => 'Учитывается доля превышения плана прошлого квартала, распределённая на три месяца (п. 5.4)'],
                ['key' => 'total', 'label' => 'План на этот месяц', 'value' => $thisMonth['plan'] ?? null, 'unit' => 'rub',
                    'note' => 'Медиана × рабочие дни × сезон × (1 + прирост) + доля перевыполнения'],
            ],
            'sample' => $calculated['sample'],
            'months' => $months,
            'rules' => [
                'Перевод партнёров к вам или от вас меняет план на их обычную закупку (п. 5.5).',
                'Отпуск или больничный уменьшают план месяца пропорционально отработанным дням — автоматически, по табелю (п. 10.1).',
                'Половина перевыполнения прошлого квартала учитывается в плане следующего (п. 5.4).',
                'Перевыполнение плана в этом месяце не повышает план следующего: повышение по этому основанию запрещено (п. 5.4).',
                'План на квартал утверждается до его начала одним решением и внутри квартала не пересматривается (п. 5.3).',
            ],
        ];
    }

    private function currentPlan(int $managerId, CarbonImmutable $month): ?float
    {
        return CrmSalesPlan::query()->forPeriod($month)->forManager($managerId)->first()?->amountValue();
    }

    private function ru(string $iso): string
    {
        return CarbonImmutable::parse($iso)->format('d.m.Y');
    }
}
