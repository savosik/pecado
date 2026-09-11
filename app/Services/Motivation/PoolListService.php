<?php

namespace App\Services\Motivation;

use App\Models\PayrollCalculation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * «Свободные клиенты» — Пул и правило крана (карточка mot-29, пп. 8.2–8.4).
 *
 * В Пуле 618 партнёров, покупали когда-либо четверо. Экран не должен выдавать
 * холодную базу за спящих клиентов: «даст вам» считается только тем, у кого
 * есть история, у остальных — «оценить нельзя». Фильтр по умолчанию — «есть
 * история покупок».
 *
 * Кран: если отгрузки базы два периода подряд ниже порога оплаты, выдача пакетов
 * приостановлена (п. 8.4). Проверяется по снимкам расчёта — тем же числам,
 * что видел работник на «Моём месяце», а не по пересчёту задним числом.
 */
class PoolListService
{
    private const PER_PAGE = 50;

    private const USUAL_MONTHS = 6;

    private const BEST_MONTHS = 24;

    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly PartnerAttributionResolver $attribution,
        private readonly PayrollParamsResolver $params,
    ) {}

    /**
     * @param  array<string, mixed>  $query  history (1|0), sort, direction, page, search
     * @return array<string, mixed>
     */
    public function list(int $managerId, CarbonInterface $month, array $query = []): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $rows = $this->dataset($period);

        $withHistory = array_values(array_filter($rows, fn (array $r): bool => $r['ever_bought']));
        $summary = [
            'total' => count($rows),
            'with_history' => count($withHistory),
            'package_size' => (int) config('motivation.default_parameters.pool_package_size', 20),
        ];

        $historyOnly = ! array_key_exists('history', $query) || in_array($query['history'], [1, '1', true, 'true'], true);
        $selected = $historyOnly ? $withHistory : $rows;

        $rateP2 = (float) ($this->params->effective($managerId, $period)->for('motivation_variable')['rate_p2']
            ?? config('motivation.default_parameters.rate_p2', 0));
        $noveltyMonths = max(1, (int) config('motivation.default_parameters.novelty_periods', 6));

        foreach ($selected as &$row) {
            // Оценка только по истории: партнёр без покупок не «спящий», а холодный.
            $row['estimate'] = $row['ever_bought'] && $row['usual_monthly'] > 0
                ? Money::round($row['usual_monthly'] * $rateP2 * $noveltyMonths)
                : null;
        }
        unset($row);

        $search = mb_strtolower(trim((string) ($query['search'] ?? '')));
        if ($search !== '') {
            $selected = array_values(array_filter($selected, fn (array $r): bool => str_contains(mb_strtolower($r['name'].' '.$r['city']), $search)));
        }

        $sort = in_array($query['sort'] ?? '', ['name', 'last_purchase_on', 'usual_monthly', 'best_month', 'estimate', 'city'], true)
            ? (string) $query['sort'] : 'estimate';
        $direction = ($query['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        usort($selected, function (array $a, array $b) use ($sort, $direction): int {
            // Сначала те, у кого есть история, — при любой сортировке.
            if ($a['ever_bought'] !== $b['ever_bought']) {
                return $a['ever_bought'] ? -1 : 1;
            }

            $va = match ($sort) {
                'best_month' => $a['best_month']['amount'],
                'estimate' => $a['estimate'] ?? 0,
                default => $a[$sort] ?? '',
            };
            $vb = match ($sort) {
                'best_month' => $b['best_month']['amount'],
                'estimate' => $b['estimate'] ?? 0,
                default => $b[$sort] ?? '',
            };
            $cmp = is_string($va) ? strcasecmp($va, (string) $vb) : ($va <=> $vb);

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        $page = max(1, (int) ($query['page'] ?? 1));
        $total = count($selected);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);

        return [
            'month' => $period->toDateString(),
            'summary' => $summary,
            'tap' => $this->tap($managerId, $period),
            'history_only' => $historyOnly,
            'rows' => [
                'data' => array_slice($selected, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'sort' => ['column' => $sort, 'direction' => $direction],
            'hint' => sprintf(
                '«Даст вам за полгода» — обычная закупка партнёра × ставка П2 × %d месяцев периода новизны. Считается только тем, кто покупал: оценивать холодную карточку нечем.',
                $noveltyMonths,
            ),
        ];
    }

    /**
     * Правило крана (п. 8.4): два расчётных периода подряд ниже порога оплаты.
     *
     * @return array{blocked: bool, checked: list<array{month: string, base: float|null, threshold: float|null, below: bool|null}>, note: string|null}
     */
    public function tap(int $managerId, CarbonImmutable $period): array
    {
        $periods = max(1, (int) config('motivation.default_parameters.pool_tap_periods', 2));
        $checked = [];
        $belowCount = 0;
        $unknown = 0;

        for ($i = 1; $i <= $periods; $i++) {
            $month = $period->subMonths($i);
            $calculation = PayrollCalculation::latestFor($managerId, $month);
            $base = null;
            $threshold = null;

            if ($calculation !== null) {
                $inputs = PayrollInputs::fromArray((array) $calculation->inputs);
                $base = $inputs->motivation?->baseRevenue;

                foreach ((array) data_get($calculation->breakdown, 'components', []) as $component) {
                    if (is_array($component) && ($component['key'] ?? null) === 'motivation_variable') {
                        $threshold = isset($component['meta']['threshold']) ? (float) $component['meta']['threshold'] : null;
                    }
                }
            }

            $below = $base === null || $threshold === null ? null : $base < $threshold;

            if ($below === null) {
                $unknown++;
            } elseif ($below) {
                $belowCount++;
            }

            $checked[] = [
                'month' => $month->toDateString(),
                'base' => $base === null ? null : Money::round($base),
                'threshold' => $threshold === null ? null : Money::round($threshold),
                'below' => $below,
            ];
        }

        $blocked = $belowCount >= $periods;

        return [
            'blocked' => $blocked,
            'checked' => array_reverse($checked),
            'note' => $unknown > 0
                ? 'За часть проверяемых месяцев расчёта по новой схеме нет — кран считается открытым.'
                : ($blocked
                    ? sprintf('Отгрузки базы %d %s подряд ниже порога оплаты. Выдача возобновится с периода, следующего за тем, в котором отгрузки достигнут порога.', $periods, $periods === 2 ? 'периода' : 'периодов')
                    : null),
        ];
    }

    /**
     * Строки Пула с историей покупок. Кэш на четверть часа: список общий для всех.
     *
     * @return list<array<string, mixed>>
     */
    private function dataset(CarbonImmutable $period): array
    {
        return Cache::remember('motivation:pool:'.$period->format('Y-m'), (int) config('crm.opportunities.cache_ttl', 900), fn (): array => $this->build($period));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(CarbonImmutable $period): array
    {
        $ids = $this->attribution->poolPartnerIds($period->endOfMonth());

        if ($ids === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $ids)->get(['id', 'name', 'erp_name', 'city', 'phone', 'email', 'created_at'])->keyBy('id');

        // История есть у единиц: сначала узнаём, у кого вообще были отгрузки,
        // и только по ним ходим за помесячной историей.
        $buyers = Shipment::query()
            ->withoutInternalOrganizations()
            ->whereIn('user_id', $ids)
            ->distinct()
            ->pluck('user_id')
            ->map('intval')
            ->all();

        $history = $this->monthlyHistory($buyers, $period);
        $rows = [];

        foreach ($ids as $id) {
            $user = $users[$id] ?? null;

            if ($user === null) {
                continue;
            }

            $months = $history[$id] ?? [];
            $bought = $months !== [] && array_sum($months) > 0;
            $last = null;
            foreach ($months as $key => $amount) {
                if ($amount > 0) {
                    $last = $key;
                }
            }

            $rows[] = [
                'id' => $id,
                'name' => (string) ($user->display_name ?? $user->name),
                'legal_name' => (string) ($user->erp_name ?? ''),
                'city' => (string) ($user->city ?? ''),
                'phone' => $user->phone,
                'email' => $user->email,
                'registered_on' => $user->created_at?->toDateString(),
                'ever_bought' => $bought,
                'last_purchase_on' => $last === null ? null : CarbonImmutable::parse($last.'-01')->endOfMonth()->toDateString(),
                'usual_monthly' => Money::round($this->median(array_slice($months, -self::USUAL_MONTHS, self::USUAL_MONTHS, true))),
                'best_month' => $this->best($months),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, float>>
     */
    private function monthlyHistory(array $ids, CarbonImmutable $period): array
    {
        if ($ids === []) {
            return [];
        }

        $ctx = AnalyticsContext::forScope($ids, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $history = [];

        for ($i = self::BEST_MONTHS; $i >= 0; $i--) {
            $month = $period->subMonths($i);
            $key = $month->format('Y-m');

            foreach ($this->analytics->byPartner($ctx, new AnalyticsFilters(
                dateFrom: $month->startOfDay(),
                dateTo: $month->endOfMonth()->endOfDay(),
            ), null) as $row) {
                if ($row['partner_id'] !== null) {
                    $history[(int) $row['partner_id']][$key] = (float) $row['amount'];
                }
            }
        }

        foreach ($history as $partnerId => $months) {
            $filled = [];
            for ($i = self::BEST_MONTHS; $i >= 0; $i--) {
                $key = $period->subMonths($i)->format('Y-m');
                $filled[$key] = $months[$key] ?? 0.0;
            }
            $history[$partnerId] = $filled;
        }

        return $history;
    }

    /**
     * @param  array<string, float>  $months
     */
    private function median(array $months): float
    {
        $values = array_values($months);

        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  array<string, float>  $months
     * @return array{amount: float, period: string|null}
     */
    private function best(array $months): array
    {
        $bestKey = null;
        $bestAmount = 0.0;

        foreach ($months as $key => $amount) {
            if ($amount > 0 && $amount >= $bestAmount) {
                $bestAmount = $amount;
                $bestKey = $key;
            }
        }

        return ['amount' => Money::round($bestAmount), 'period' => $bestKey];
    }
}
