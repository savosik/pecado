<?php

namespace App\Services\Motivation;

use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Фиксация базы гарантии переходного периода (карточка mot-40; п. 12.3 Положения).
 *
 * База среднего — оплата труда за три периода, предшествующих введению Положения, —
 * фиксируется один раз на дату введения и дальше не пересчитывается: иначе доплата
 * гонялась бы за собственным хвостом. Хранится персональным отклонением компонента
 * гарантии вместе со сроком действия (первый квартал новой системы), чтобы доплата
 * была воспроизводима и видна на экране «Параметры мотивации → По работнику».
 */
class GuaranteeBaseService
{
    private const PERIODS = 3;

    private const GUARANTEE_MONTHS = 3;

    public function __construct(
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollParamsResolver $params,
        private readonly ParameterOrderService $orders,
    ) {}

    /**
     * Зафиксировать базу всем работникам на схеме.
     *
     * @return array{effective_from: string, until: string, rows: list<array<string, mixed>>}
     */
    public function fix(CarbonInterface $effectiveFrom, User $actor, bool $overwrite = false): array
    {
        $effective = CarbonImmutable::instance($effectiveFrom)->startOfMonth();
        $until = $effective->addMonths(self::GUARANTEE_MONTHS);
        $share = (float) ($this->orders->effective($effective)['values']['transition_guarantee_share'] ?? config('motivation.default_parameters.transition_guarantee_share', 0));

        $rows = [];
        foreach (PersonalManager::query()->where('payroll_enabled', true)->orderBy('name')->get() as $manager) {
            $managerId = (int) $manager->getKey();
            $current = $this->params->layer($managerId, null)['motivation_guarantee'] ?? [];
            $alreadyFixed = (float) ($current['base'] ?? 0) > 0;

            $months = [];
            for ($i = self::PERIODS; $i >= 1; $i--) {
                $month = $effective->subMonths($i);
                $calculation = PayrollCalculation::latestFor($managerId, $month) ?? $this->calculations->ensureDraft($managerId, $month);
                $months[] = [
                    'month' => $month->toDateString(),
                    'total' => (float) $calculation->total,
                    'status' => $calculation->status,
                ];
            }

            $average = Money::round(array_sum(array_column($months, 'total')) / self::PERIODS);

            $row = [
                'manager' => ['id' => $managerId, 'name' => (string) $manager->name],
                'months' => $months,
                'average' => $average,
                'share' => $share,
                'minimum' => Money::round($average * $share),
                'previous_base' => $alreadyFixed ? (float) $current['base'] : null,
                'skipped' => $alreadyFixed && ! $overwrite,
            ];

            if (! $row['skipped']) {
                $this->orders->savePersonal($managerId, 'motivation_guarantee', [
                    'share' => $share,
                    'base' => $average,
                    'until' => $until->toDateString(),
                ], $actor, sprintf('База гарантии п. 12.3: среднее за %s — %s', $months[0]['month'], end($months)['month']));
            }

            $rows[] = $row;
        }

        return ['effective_from' => $effective->toDateString(), 'until' => $until->toDateString(), 'rows' => $rows];
    }
}
