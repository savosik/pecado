<?php

namespace App\Listeners\Payroll;

use App\Events\Payroll\PayrollInputsChanged;
use App\Jobs\Payroll\RecalculatePayrollDraft;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;

/**
 * Входы изменились → пересчитать черновики затронутых менеджеров.
 *
 * Какие месяцы: текущий, месяцы из события (не позже текущего) и все открытые
 * черновики менеджера за прошлые месяцы — поздняя оплата за август должна
 * попасть в августовский черновик, пока РОП его не утвердил. Прошлые месяцы
 * без черновика не трогаем: снимок появится, когда его откроют.
 */
class ScheduleDraftRecalculation
{
    public function __construct(private readonly PayrollCalculationService $calculations) {}

    public function handle(PayrollInputsChanged $event): void
    {
        $current = CarbonImmutable::now()->startOfMonth();
        $delay = max(0, (int) config('payroll.recalculate.debounce_seconds', 60));

        foreach (array_unique(array_map('intval', $event->managerIds)) as $managerId) {
            $months = [$current->toDateString()];

            foreach ($event->months as $month) {
                $period = CarbonImmutable::parse($month)->startOfMonth();
                if ($period->lte($current)) {
                    $months[] = $period->toDateString();
                }
            }

            $open = $this->calculations->openDraftMonths($managerId);

            foreach (array_unique($months) as $month) {
                if ($month !== $current->toDateString() && ! in_array($month, $open, true)) {
                    continue;
                }

                RecalculatePayrollDraft::dispatch($managerId, $month, $event->source)
                    ->delay(now()->addSeconds($delay));
            }

            foreach ($open as $month) {
                if (! in_array($month, $months, true)) {
                    RecalculatePayrollDraft::dispatch($managerId, $month, $event->source)
                        ->delay(now()->addSeconds($delay));
                }
            }
        }
    }
}
