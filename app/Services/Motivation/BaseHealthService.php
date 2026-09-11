<?php

namespace App\Services\Motivation;

use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * «Здоровье базы» — что происходит с клиентской базой отдела (карточка mot-38; форма B5).
 *
 * Семь показателей на работника и на отдел. Считаются теми же строками, что
 * питают «Мою базу», «Кого разбудить» и «Долги»: расхождение между сводкой
 * руководителя и экраном работника недопустимо, поэтому второго расчёта нет.
 * Каждый показатель раскрывается в список партнёров — ссылкой на экран работника.
 */
class BaseHealthService
{
    private const CONCENTRATION_LIMIT = 0.40;

    private const OVERDUE_LIMIT = 0.25;

    private const SLEEP_DAYS = 90;

    public function __construct(
        private readonly PartnerListService $partners,
        private readonly PartnerAttributionResolver $attribution,
        private readonly QuarterReferenceService $quarter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $previous = $period->subMonth();

        $rows = [];
        foreach (PersonalManager::query()->where('payroll_enabled', true)->orderBy('name')->get() as $manager) {
            $rows[] = $this->row((int) $manager->getKey(), (string) $manager->name, $period, $previous);
        }

        $quarter = $this->quarter->build($period);
        $poolIds = $this->attribution->poolPartnerIds($period->endOfMonth());
        $poolWithHistory = $poolIds === [] ? 0 : Shipment::query()->withoutInternalOrganizations()->whereIn('user_id', $poolIds)->distinct()->count('user_id');

        $sum = fn (string $key): float => array_sum(array_map(fn (array $r): float => (float) $r[$key], $rows));
        $revenue = $sum('revenue');
        $topAmount = max([0.0, ...array_map(fn (array $r): float => (float) $r['top_partner']['amount'], $rows)]);

        return [
            'month' => $period->toDateString(),
            'rows' => $rows,
            'department' => [
                'revenue' => Money::round($revenue),
                'concentration' => $revenue > 0 ? round($topAmount / $revenue, 4) : null,
                'partners_total' => (int) $sum('partners_total'),
                'active' => (int) $sum('active'),
                'active_previous' => (int) $sum('active_previous'),
                'never_bought' => (int) $sum('never_bought'),
                'sleeping' => (int) $sum('sleeping'),
                'overdue' => Money::round($sum('overdue')),
                'overdue_share' => $revenue > 0 ? round($sum('overdue') / $revenue, 4) : null,
            ],
            'quarter' => [
                'label' => $quarter['quarter_label'],
                'candidates' => (int) $quarter['candidates_count'],
                'qualified' => (int) $quarter['qualified_count'],
                'next_step' => $quarter['next_step'],
                'href' => '/crm/motivation/quarter/admin',
            ],
            'pool' => [
                'total' => count($poolIds),
                'with_history' => $poolWithHistory,
                'href' => '/crm/motivation/pool/admin',
            ],
            'limits' => [
                'concentration' => self::CONCENTRATION_LIMIT,
                'overdue' => self::OVERDUE_LIMIT,
                'sleep_days' => self::SLEEP_DAYS,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $managerId, string $name, CarbonImmutable $period, CarbonImmutable $previous): array
    {
        $rows = $this->partners->all($managerId, $period);
        $before = $this->partners->all($managerId, $previous);

        $revenue = array_sum(array_map(fn (array $r): float => (float) $r['current_month'], $rows));
        $top = null;
        foreach ($rows as $r) {
            if ($top === null || (float) $r['current_month'] > (float) $top['current_month']) {
                $top = $r;
            }
        }
        $topAmount = $top === null ? 0.0 : (float) $top['current_month'];
        $concentration = $revenue > 0 ? round($topAmount / $revenue, 4) : null;

        $active = count(array_filter($rows, fn (array $r): bool => (float) $r['current_month'] > 0));
        $activeBefore = count(array_filter($before, fn (array $r): bool => (float) $r['current_month'] > 0));
        $never = count(array_filter($rows, fn (array $r): bool => ! $r['ever_bought']));
        $sleeping = count(array_filter($rows, fn (array $r): bool => $r['ever_bought'] && ($r['silent_days'] ?? 0) > self::SLEEP_DAYS));
        $overdue = array_sum(array_map(fn (array $r): float => ! empty($r['debt']['overdue']) ? (float) $r['debt']['amount'] : 0.0, $rows));
        $overdueShare = $revenue > 0 ? round($overdue / $revenue, 4) : null;

        $link = fn (string $path, array $extra = []): string => $path.'?'.http_build_query(['manager' => $managerId, 'month' => $period->format('Y-m')] + $extra);

        return [
            'manager' => ['id' => $managerId, 'name' => $name],
            'revenue' => Money::round($revenue),
            'partners_total' => count($rows),
            'top_partner' => ['id' => $top['id'] ?? null, 'name' => $top['name'] ?? null, 'amount' => Money::round($topAmount)],
            'concentration' => $concentration,
            'concentration_alert' => $concentration !== null && $concentration > self::CONCENTRATION_LIMIT,
            'active' => $active,
            'active_previous' => $activeBefore,
            'active_alert' => $activeBefore > 0 && $active < $activeBefore,
            'never_bought' => $never,
            'never_bought_share' => count($rows) > 0 ? round($never / count($rows), 4) : null,
            'sleeping' => $sleeping,
            'overdue' => Money::round($overdue),
            'overdue_share' => $overdueShare,
            'overdue_alert' => $overdueShare !== null && $overdueShare > self::OVERDUE_LIMIT,
            'links' => [
                'base' => $link('/crm/motivation/base', ['filter' => 'all']),
                'active' => $link('/crm/motivation/base', ['filter' => 'active']),
                'never' => $link('/crm/motivation/base', ['filter' => 'never']),
                'sleeping' => $link('/crm/motivation/wake'),
                'debts' => $link('/crm/motivation/debts'),
            ],
        ];
    }
}
