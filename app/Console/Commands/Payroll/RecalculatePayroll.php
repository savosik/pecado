<?php

namespace App\Console\Commands\Payroll;

use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Пересчёт черновиков зарплаты.
 *
 * `--stale` — страховка событийного пересчёта: черновики текущего месяца, которые
 * давно не пересчитывались, и менеджеры, у которых черновика ещё нет. Остальные
 * режимы — для рук: один менеджер, все за месяц.
 */
class RecalculatePayroll extends Command
{
    protected $signature = 'payroll:recalculate
        {--manager= : id карточки менеджера (personal_managers.id)}
        {--month= : Месяц Y-m; по умолчанию текущий}
        {--stale : Только черновики старше payroll.recalculate.stale_after_minutes и отсутствующие}';

    protected $description = 'Пересчитать черновики зарплаты менеджеров за месяц';

    public function handle(PayrollCalculationService $service): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::parse((string) $this->option('month').'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $managerIds = $this->managerIds($service, $month);

        if ($managerIds === []) {
            $this->info('Пересчитывать нечего.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($managerIds as $managerId) {
            $calculation = $service->recalculateDraft($managerId, $month, 'console');

            $rows[] = [
                $managerId,
                (string) (PersonalManager::query()->whereKey($managerId)->value('name') ?? '—'),
                $calculation === null ? '— (заморожен)' : Money::rub((float) $calculation->total),
                $calculation === null ? '' : $calculation->statusLabel(),
            ];
        }

        $this->table(['id', 'Менеджер', 'Итог', 'Статус'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function managerIds(PayrollCalculationService $service, CarbonImmutable $month): array
    {
        if ($this->option('manager')) {
            return [(int) $this->option('manager')];
        }

        $active = PersonalManager::query()
            ->active()
            ->whereNotNull('user_id')
            ->pluck('id')
            ->map('intval')
            ->all();

        if (! $this->option('stale')) {
            return $active;
        }

        $minutes = max(1, (int) config('payroll.recalculate.stale_after_minutes', 10));
        $stale = $service->staleDrafts($month, $minutes)->pluck('personal_manager_id')->map('intval')->all();

        $withDraft = PayrollCalculation::query()
            ->forPeriod($month)
            ->pluck('personal_manager_id')
            ->map('intval')
            ->all();

        $missing = array_values(array_diff($active, $withDraft));

        return array_values(array_unique(array_merge($stale, $missing)));
    }
}
