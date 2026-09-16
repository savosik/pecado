<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationPoolPackageItem;
use App\Models\PersonalManager;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Счётчики вкладок «Мои клиенты»: сколько строк за каждой вкладкой.
 *
 * Считаются теми же сервисами, что и сами вкладки, — цифра на вкладке обязана
 * совпадать с числом строк за ней. Кэш пять минут на работника и месяц: вкладки
 * переключают часто, а долги требуют черновика расчёта.
 */
class ClientTabCounts
{
    private const TTL = 300;

    public function __construct(
        private readonly PartnerListService $partners,
        private readonly PayrollCalculationService $calculations,
        private readonly DebtListService $debts,
    ) {}

    /**
     * @return array{all: int, packages: int, new: int, rhythm: int, debts: int|null, wake: int}
     */
    public function forManager(int $managerId, CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();

        return Cache::remember(
            sprintf('motivation:tab-counts:%d:%s', $managerId, $period->format('Y-m')),
            self::TTL,
            fn (): array => $this->build($managerId, $period),
        );
    }

    public static function forget(int $managerId, CarbonInterface $month): void
    {
        Cache::forget(sprintf('motivation:tab-counts:%d:%s', $managerId, CarbonImmutable::instance($month)->format('Y-m')));
    }

    /**
     * @return array{all: int, packages: int, new: int, rhythm: int, debts: int|null, wake: int}
     */
    private function build(int $managerId, CarbonImmutable $period): array
    {
        $rows = $this->partners->all($managerId, $period);

        $rhythm = count(array_filter($rows, fn (array $r): bool => $r['ever_bought']
            && (($r['flags']['drop'] ?? false) || ($r['flags']['stopped'] ?? false) || ($r['flags']['silent'] ?? false))));
        $wake = count(array_filter($rows, fn (array $r): bool => ! $r['ever_bought']
            || ($r['current_month'] <= 0 && ($r['silent_days'] ?? 0) >= PartnerListService::WAKE_SILENT_DAYS)));

        $packages = MotivationPoolPackageItem::query()
            ->where('outcome', MotivationPoolPackageItem::OUTCOME_IN_PROGRESS)
            ->whereHas('package', fn ($q) => $q->where('personal_manager_id', $managerId))
            ->count();

        $debts = null;
        $manager = PersonalManager::query()->find($managerId);
        if ($manager !== null && $manager->payroll_enabled) {
            $calculation = $this->calculations->ensureDraft($managerId, $period);
            $debts = (int) $this->debts->build($calculation)['summary']['partners_count'];
        }

        return [
            'all' => count($rows),
            'packages' => $packages,
            'new' => count(array_filter($rows, fn (array $r): bool => (bool) $r['in_novelty'])),
            'rhythm' => $rhythm,
            'debts' => $debts,
            'wake' => $wake,
        ];
    }
}
