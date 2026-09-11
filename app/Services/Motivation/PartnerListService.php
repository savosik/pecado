<?php

namespace App\Services\Motivation;

use App\Models\CrmCall;
use App\Models\CrmEmail;
use App\Models\CrmTask;
use App\Models\DebtState;
use App\Models\Motivation\MotivationPartnerNovelty;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Crm\OpportunityService;
use App\Services\Crm\PlanScope;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Списки партнёров работника: «Моя база» и «Кто выпал из ритма» (карточка mot-27).
 *
 * Поверх сигналов {@see OpportunityService::signals()} — там уже есть факт месяца,
 * последняя покупка, цикл и класс. Сюда добавлено то, чего у сигналов нет:
 * обычная закупка (медиана за полгода), лучший месяц за два года, ассортимент,
 * долг, новизна и «что это стоит вам» по действующей ставке П1.
 *
 * Помесячная история берётся через сервис аналитики по одному месяцу за раз
 * и кэшируется: свой SUM по отгрузкам был бы вторым движком выручки.
 */
class PartnerListService
{
    private const PER_PAGE = 50;

    private const USUAL_MONTHS = 6;

    private const BEST_MONTHS = 24;

    private const ASSORTMENT_MONTHS = 12;

    /** Молчание дольше этого срока выводит партнёра во вкладку «молчат» экрана «Кого разбудить». */
    private const WAKE_SILENT_DAYS = 90;

    /** Сколько ключевых позиций показывать в «что брал». */
    private const TOP_PRODUCTS = 3;

    /** Колонки, по которым можно сортировать с сервера. */
    private const SORTABLE = [
        'name', 'usual_monthly', 'current_month', 'best_month', 'potential', 'your_gain',
        'assortment', 'last_purchase_on', 'silent_days', 'debt', 'shortfall', 'cost',
    ];

    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly ShipmentAnalyticsService $analytics,
        private readonly PartnerAttributionResolver $attribution,
        private readonly PayrollParamsResolver $params,
    ) {}

    /**
     * «Моя база»: все партнёры работника.
     *
     * @param  array<string, mixed>  $query  filter (all|active|silent|never), sort, direction, page, search
     * @return array<string, mixed>
     */
    public function base(int $managerId, CarbonInterface $month, array $query = []): array
    {
        $rows = $this->dataset($managerId, $month);

        $summary = [
            'total' => count($rows),
            'active' => count(array_filter($rows, fn (array $r): bool => $r['current_month'] > 0)),
            'silent' => count(array_filter($rows, fn (array $r): bool => $r['current_month'] <= 0 && $r['ever_bought'])),
            'never_bought' => count(array_filter($rows, fn (array $r): bool => ! $r['ever_bought'])),
            'in_novelty' => count(array_filter($rows, fn (array $r): bool => $r['in_novelty'])),
        ];

        $filter = (string) ($query['filter'] ?? 'bought');
        $rows = array_values(array_filter($rows, fn (array $r): bool => match ($filter) {
            'active' => $r['current_month'] > 0,
            'silent' => $r['current_month'] <= 0 && $r['ever_bought'],
            'never' => ! $r['ever_bought'],
            'all' => true,
            default => $r['ever_bought'],   // рабочий список — те, кто покупал хоть раз
        }));

        return $this->page($rows, $query, 'potential', 'desc', $month, $summary, $filter,
            'Потенциал — разница между лучшим месяцем партнёра за два года и его закупкой в этом месяце. «Даст вам» — потенциал по ставке П1.');
    }

    /**
     * «Кто выпал из ритма»: кому позвонить, чтобы дожать месяц.
     *
     * Фильтры включены по умолчанию все три; снятие всех показывает всю
     * покупавшую базу — тогда включается пагинация.
     *
     * @param  array<string, mixed>  $query  drop, stopped, silent (1|0), sort, direction, page
     * @return array<string, mixed>
     */
    public function rhythm(int $managerId, CarbonInterface $month, array $query = []): array
    {
        $rows = array_values(array_filter($this->dataset($managerId, $month), fn (array $r): bool => $r['ever_bought']));

        $flags = [
            'drop' => $this->flag($query, 'drop'),
            'stopped' => $this->flag($query, 'stopped'),
            'silent' => $this->flag($query, 'silent'),
        ];

        $summary = [
            'total' => count($rows),
            'drop' => count(array_filter($rows, fn (array $r): bool => $r['flags']['drop'])),
            'stopped' => count(array_filter($rows, fn (array $r): bool => $r['flags']['stopped'])),
            'silent' => count(array_filter($rows, fn (array $r): bool => $r['flags']['silent'])),
        ];

        if ($flags['drop'] || $flags['stopped'] || $flags['silent']) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ($flags['drop'] && $r['flags']['drop'])
                || ($flags['stopped'] && $r['flags']['stopped'])
                || ($flags['silent'] && $r['flags']['silent'])));
        }

        $summary['cost_total'] = Money::round(array_sum(array_map(fn (array $r): float => $r['cost'], $rows)));

        return $this->page($rows, $query, 'cost', 'desc', $month, $summary, $flags,
            '«Что это стоит вам» — недобор до обычной закупки по ставке П1. Список отвечает на вопрос, кому звонить первым, а не кто больше просел в процентах.');
    }

    /**
     * «Кого разбудить»: две вкладки, которые нельзя смешивать.
     *
     * Основная — закреплённые партнёры без единой покупки: по принятому определению
     * каждый при первой покупке даёт повышенную ставку на полгода. Вторая — молчащие
     * дольше трёх месяцев: повышенной ставки они не дают, но работать с ними стоит.
     * Обратный отсчёт до признания партнёра Новым не показывается намеренно —
     * иначе выгодно придержать возврат ради повышенной ставки.
     *
     * @param  array<string, mixed>  $query  tab (never|silent), sort, direction, page, search
     * @return array<string, mixed>
     */
    public function wake(int $managerId, CarbonInterface $month, array $query = []): array
    {
        $rows = $this->dataset($managerId, $month);
        $tab = ($query['tab'] ?? 'never') === 'silent' ? 'silent' : 'never';

        $never = array_values(array_filter($rows, fn (array $r): bool => ! $r['ever_bought']));
        $silent = array_values(array_filter(
            $rows,
            fn (array $r): bool => $r['ever_bought']
                && $r['current_month'] <= 0
                && ($r['silent_days'] ?? 0) >= self::WAKE_SILENT_DAYS,
        ));

        $summary = ['never' => count($never), 'silent' => count($silent)];
        $selected = $tab === 'silent' ? $silent : $never;

        if ($tab === 'silent' && $selected !== []) {
            $products = $this->topProducts(array_column($selected, 'id'));

            foreach ($selected as &$row) {
                $row['top_products'] = $products[$row['id']] ?? [];
                $row['silent_months'] = (int) floor(($row['silent_days'] ?? 0) / 30);
            }
            unset($row);
        }

        $defaultSort = $tab === 'silent' ? 'best_month' : 'name';

        return $this->page($selected, $query, $defaultSort, $tab === 'silent' ? 'desc' : 'asc', $month, $summary, $tab,
            $tab === 'silent'
                ? 'Партнёры, не покупавшие дольше трёх месяцев. Повышенной ставки они не дают: перерыв короче года.'
                : 'Партнёры, по которым продаж не было ни разу. Первая покупка каждого даёт повышенную ставку П2 на шесть месяцев.');
    }

    /**
     * «Мои новые клиенты»: партнёры в Периоде новизны и их вклад в П2 и в премию отдела.
     *
     * Сумма «ваше вознаграждение с него» равна показателю П2 расчёта — с точностью
     * до возвратов, которые вычитаются из группы, а не из партнёра.
     *
     * @return array<string, mixed>
     */
    public function newcomers(int $managerId, CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $rows = array_values(array_filter($this->dataset($managerId, $period), fn (array $r): bool => $r['in_novelty']));
        $ids = array_column($rows, 'id');

        $params = $this->motivationParams($managerId, $period);
        $rateP2 = (float) ($params['rate_p2'] ?? config('motivation.default_parameters.rate_p2', 0));
        $threshold = (float) config('motivation.default_parameters.quarterly_qualification_amount', 0);
        $noveltyMonths = max(1, (int) config('motivation.default_parameters.novelty_periods', 6));

        $novelty = $ids === [] ? [] : MotivationPartnerNovelty::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $quarter = $this->quarterAmounts($ids, $period);

        $result = [];
        foreach ($rows as $row) {
            /** @var MotivationPartnerNovelty|null $cache */
            $cache = $novelty[$row['id']] ?? null;
            $endsOn = $cache?->novelty_ends_on;
            $monthsLeft = $endsOn === null ? 0 : max(0, (int) $period->diffInMonths(CarbonImmutable::instance($endsOn)->startOfMonth()) + 1);
            $quarterAmount = (float) ($quarter[$row['id']] ?? 0);

            $result[] = $row + [
                'novelty_started_on' => $cache?->novelty_started_on?->toDateString(),
                'novelty_ends_on' => $endsOn?->toDateString(),
                'months_left' => min($noveltyMonths, $monthsLeft),
                'months_total' => $noveltyMonths,
                'history_incomplete' => (bool) ($cache->history_incomplete ?? false),
                'reward' => Money::round($row['current_month'] * $rateP2),
                'quarter_amount' => Money::round($quarterAmount),
                'to_qualification' => Money::round(max(0.0, $threshold - $quarterAmount)),
                'qualified' => $quarterAmount >= $threshold,
            ];
        }

        usort($result, fn (array $a, array $b): int => $b['reward'] <=> $a['reward']);

        return [
            'month' => $period->toDateString(),
            'summary' => [
                'total' => count($result),
                'reward_total' => Money::round(array_sum(array_column($result, 'reward'))),
                'qualified' => count(array_filter($result, fn (array $r): bool => $r['qualified'])),
                'threshold' => $threshold,
                'rate_p2' => $rateP2,
            ],
            'rows' => $result,
            'hint' => 'Премия отдела за квартал начисляется отдельно и на месячный доход не влияет: «до зачёта» — это про премию отдела, а не про ваши '.'выплаты.',
        ];
    }

    /**
     * Строки по всем партнёрам работника за месяц. Кэшируется на четверть часа:
     * фильтры и сортировка переключаются поверх одного набора.
     *
     * @return list<array<string, mixed>>
     */
    private function dataset(int $managerId, CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $key = sprintf('motivation:partners:%d:%s', $managerId, $period->format('Y-m'));

        return Cache::remember($key, (int) config('crm.opportunities.cache_ttl', 900), fn (): array => $this->build($managerId, $period));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(int $managerId, CarbonImmutable $period): array
    {
        $names = $this->attribution->partnersOf($managerId, $period->endOfMonth());
        $ids = array_keys($names);

        if ($ids === []) {
            return [];
        }

        $scope = PlanScope::manager($managerId, $ids, (string) $managerId);
        $signals = $this->opportunities->signals($period, $scope);

        $history = $this->monthlyHistory($ids, $period);
        $assortment = $this->assortment($ids, $period);
        $debts = $this->debts($ids);
        $touches = $this->lastTouches($ids);
        $novelty = $this->novelty($ids, $period);
        $rateP1 = $this->rateP1($managerId, $period);
        $dropThreshold = (int) config('crm.opportunities.drop_threshold_percent', 25) / 100;

        $rows = [];

        foreach ($ids as $id) {
            $signal = $signals[$id] ?? [];
            $months = $history[$id] ?? [];

            $current = (float) ($signal['fact'] ?? 0);
            $usual = $this->median(array_slice($months, -self::USUAL_MONTHS, self::USUAL_MONTHS, true));
            $best = $this->best($months);
            $everBought = $best['amount'] > 0 || $current > 0 || ($signal['last_purchase_at'] ?? null) !== null;

            $potential = Money::round(max(0.0, $best['amount'] - $current));
            $shortfall = Money::round(max(0.0, $usual - $current));
            $silentDays = $signal['days_since'] ?? null;
            $cycle = (int) ($signal['cycle_days'] ?? 0);

            $rows[] = [
                'id' => $id,
                'name' => (string) ($signal['name'] ?? $names[$id]),
                'usual_monthly' => Money::round($usual),
                'current_month' => Money::round($current),
                'best_month' => $best,
                'potential' => $potential,
                'your_gain' => Money::round($potential * $rateP1),
                'shortfall' => $shortfall,
                'cost' => Money::round($shortfall * $rateP1),
                'assortment' => $assortment['by_partner'][$id] ?? ['taken' => 0, 'total' => $assortment['total']],
                'last_purchase_on' => isset($signal['last_purchase_at']) ? CarbonImmutable::parse((string) $signal['last_purchase_at'])->toDateString() : null,
                'silent_days' => $silentDays,
                'cycle_days' => $cycle,
                'abc' => $signal['abc'] ?? null,
                'debt' => $debts[$id] ?? null,
                'last_touch' => $touches[$id] ?? null,
                'in_novelty' => in_array($id, $novelty, true),
                'ever_bought' => $everBought,
                'flags' => [
                    'drop' => $usual > 0 && $current < $usual * (1 - $dropThreshold),
                    'stopped' => $usual > 0 && $current <= 0,
                    'silent' => $silentDays !== null && $cycle > 0 && $silentDays > $cycle,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Сортировка, отбор по строке и страница.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function page(array $rows, array $query, string $defaultSort, string $defaultDirection, CarbonInterface $month, array $summary, mixed $filter, string $hint): array
    {
        $search = mb_strtolower(trim((string) ($query['search'] ?? '')));

        if ($search !== '') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => str_contains(mb_strtolower($r['name']), $search)));
        }

        $sort = in_array($query['sort'] ?? '', self::SORTABLE, true) ? (string) $query['sort'] : $defaultSort;
        $direction = ($query['direction'] ?? $defaultDirection) === 'asc' ? 'asc' : 'desc';

        usort($rows, function (array $a, array $b) use ($sort, $direction): int {
            $va = $this->sortValue($a, $sort);
            $vb = $this->sortValue($b, $sort);
            $cmp = is_string($va) ? strcasecmp($va, (string) $vb) : ($va <=> $vb);

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        $page = max(1, (int) ($query['page'] ?? 1));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);

        return [
            'month' => CarbonImmutable::instance($month)->startOfMonth()->toDateString(),
            'summary' => $summary,
            'filter' => $filter,
            'search' => $search,
            'rows' => [
                'data' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'sort' => ['column' => $sort, 'direction' => $direction],
            'hint' => $hint,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sortValue(array $row, string $sort): mixed
    {
        return match ($sort) {
            'name' => $row['name'],
            'best_month' => $row['best_month']['amount'],
            'assortment' => $row['assortment']['taken'],
            'debt' => $row['debt']['amount'] ?? 0.0,
            'last_purchase_on' => $row['last_purchase_on'] ?? '',
            'silent_days' => $row['silent_days'] ?? PHP_INT_MAX,
            default => $row[$sort] ?? 0,
        };
    }

    /**
     * Помесячные отгрузки партнёров за два года: partner_id → [Y-m → сумма], по возрастанию месяцев.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, float>>
     */
    private function monthlyHistory(array $ids, CarbonImmutable $period): array
    {
        $ctx = AnalyticsContext::forScope($ids, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $history = [];

        for ($i = self::BEST_MONTHS; $i >= 1; $i--) {
            $month = $period->subMonths($i);
            $key = $month->format('Y-m');

            $rows = $this->analytics->byPartner($ctx, new AnalyticsFilters(
                dateFrom: $month->startOfDay(),
                dateTo: $month->endOfMonth()->endOfDay(),
            ), null);

            foreach ($rows as $row) {
                if ($row['partner_id'] !== null) {
                    $history[(int) $row['partner_id']][$key] = (float) $row['amount'];
                }
            }
        }

        // Месяцы без отгрузок — нули, а не пропуски: медиана обычной закупки
        // должна видеть простой, иначе партнёр, купивший один раз за полгода,
        // получит «обычную закупку» равной той единственной отгрузке.
        foreach ($history as $partnerId => $months) {
            $filled = [];
            for ($i = self::BEST_MONTHS; $i >= 1; $i--) {
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

        // При равных суммах берётся более поздний месяц: «лучший» в прошлом
        // квартале говорит о партнёре больше, чем такой же два года назад.
        foreach ($months as $key => $amount) {
            if ($amount > 0 && $amount >= $bestAmount) {
                $bestAmount = $amount;
                $bestKey = $key;
            }
        }

        return ['amount' => Money::round($bestAmount), 'period' => $bestKey];
    }

    /**
     * Ассортимент: сколько корневых категорий берёт партнёр из тех, что берёт вся база работника за год.
     *
     * Считаются корневые категории дерева, а не листья: листьев больше двухсот,
     * и полоса «2 из 125» у всех выглядела бы пустой, ничего не говоря о широте
     * закупки. «Доступные» — не весь каталог, а те корни, из которых у этого
     * работника кто-то покупает.
     *
     * @param  list<int>  $ids
     * @return array{total: int, by_partner: array<int, array{taken: int, total: int}>}
     */
    private function assortment(array $ids, CarbonImmutable $period): array
    {
        $rows = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->join('products', 'products.id', '=', 'shipment_items.product_id')
            ->whereIn('shipments.user_id', $ids)
            ->whereNotNull('products.category_id')
            ->whereBetween('shipments.erp_created_at', [
                $period->subMonths(self::ASSORTMENT_MONTHS)->startOfDay(),
                $period->endOfMonth()->endOfDay(),
            ])
            ->distinct()
            ->get(['shipments.user_id', 'products.category_id']);

        if ($rows->isEmpty()) {
            return ['total' => 0, 'by_partner' => []];
        }

        $roots = $this->rootCategories();
        $byPartner = [];
        $all = [];

        foreach ($rows as $row) {
            $root = $roots[(int) $row->category_id] ?? (int) $row->category_id;
            $byPartner[(int) $row->user_id][$root] = true;
            $all[$root] = true;
        }

        $total = count($all);
        $result = [];

        foreach ($byPartner as $partnerId => $categories) {
            $result[$partnerId] = ['taken' => count($categories), 'total' => $total];
        }

        return ['total' => $total, 'by_partner' => $result];
    }

    /**
     * Категория → её корень по дереву. Дерево небольшое (около двухсот узлов),
     * поэтому читается целиком и сворачивается в PHP.
     *
     * @return array<int, int>
     */
    private function rootCategories(): array
    {
        $parents = DB::table('categories')->pluck('parent_id', 'id')->all();
        $roots = [];

        foreach (array_keys($parents) as $id) {
            $cursor = (int) $id;
            $guard = 0;

            while ($parents[$cursor] !== null && $guard++ < 32) {
                $cursor = (int) $parents[$cursor];
            }

            $roots[(int) $id] = $cursor;
        }

        return $roots;
    }

    /**
     * Просроченная задолженность партнёра — по регистру взаиморасчётов.
     *
     * @param  list<int>  $ids
     * @return array<int, array{amount: float, overdue: bool, days: int, level: string}>
     */
    private function debts(array $ids): array
    {
        $rows = [];

        foreach (DebtState::query()->partners()->whereIn('user_id', $ids)->get() as $state) {
            $overdue = (float) $state->overdue_amount;

            if ($overdue <= 0 && (float) $state->debt_amount <= 0) {
                continue;
            }

            $rows[(int) $state->user_id] = [
                'amount' => Money::round($overdue > 0 ? $overdue : (float) $state->debt_amount),
                'overdue' => $overdue > 0,
                'days' => (int) $state->age_days,
                'level' => $state->level->value,
            ];
        }

        return $rows;
    }

    /**
     * Последнее касание: звонок, письмо или задача — что было позже.
     *
     * @param  list<int>  $ids
     * @return array<int, array{at: string, kind: string}>
     */
    private function lastTouches(array $ids): array
    {
        $sources = [
            'call' => CrmCall::query()->whereIn('client_user_id', $ids)->selectRaw('client_user_id, MAX(started_at) AS at')->groupBy('client_user_id'),
            'email' => CrmEmail::query()->whereIn('client_user_id', $ids)->whereNotNull('sent_at')->selectRaw('client_user_id, MAX(sent_at) AS at')->groupBy('client_user_id'),
            'task' => CrmTask::query()->whereIn('client_user_id', $ids)->selectRaw('client_user_id, MAX(created_at) AS at')->groupBy('client_user_id'),
        ];

        $touches = [];

        foreach ($sources as $kind => $query) {
            foreach ($query->get() as $row) {
                $at = (string) $row->getAttribute('at');
                $partnerId = (int) $row->getAttribute('client_user_id');

                if ($at === '' || (isset($touches[$partnerId]) && $touches[$partnerId]['at'] >= $at)) {
                    continue;
                }

                $touches[$partnerId] = ['at' => $at, 'kind' => $kind];
            }
        }

        return $touches;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function novelty(array $ids, CarbonImmutable $period): array
    {
        return MotivationPartnerNovelty::query()
            ->whereIn('user_id', $ids)
            ->newInMonth($period)
            ->pluck('user_id')
            ->map('intval')
            ->all();
    }

    /**
     * Ключевые позиции партнёра за всю историю — что он брал, когда покупал.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{name: string, amount: float}>>
     */
    private function topProducts(array $ids): array
    {
        $rows = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->whereIn('shipments.user_id', $ids)
            ->groupBy('shipments.user_id', 'shipment_items.product_id')
            ->orderByDesc('total')
            ->get([
                'shipments.user_id',
                'shipment_items.product_id',
                DB::raw('MAX(COALESCE(products.name, shipment_items.product_name_snapshot)) AS name'),
                DB::raw('SUM(shipment_items.total) AS total'),
            ]);

        $result = [];

        foreach ($rows as $row) {
            $partnerId = (int) $row->user_id;

            if (count($result[$partnerId] ?? []) >= self::TOP_PRODUCTS) {
                continue;
            }

            $result[$partnerId][] = ['name' => (string) $row->name, 'amount' => Money::round((float) $row->total)];
        }

        return $result;
    }

    /**
     * Отгрузки партнёров за квартал, в который входит месяц.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function quarterAmounts(array $ids, CarbonImmutable $period): array
    {
        if ($ids === []) {
            return [];
        }

        $ctx = AnalyticsContext::forScope($ids, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $amounts = [];

        foreach ($this->analytics->byPartner($ctx, new AnalyticsFilters(
            dateFrom: $period->startOfQuarter()->startOfDay(),
            dateTo: $period->endOfQuarter()->endOfDay(),
        ), null) as $row) {
            if ($row['partner_id'] !== null) {
                $amounts[(int) $row['partner_id']] = (float) $row['amount'];
            }
        }

        return $amounts;
    }

    /**
     * @return array<string, mixed>
     */
    private function motivationParams(int $managerId, CarbonImmutable $period): array
    {
        return $this->params->effective($managerId, $period)->for('motivation_variable');
    }

    private function rateP1(int $managerId, CarbonImmutable $period): float
    {
        return (float) ($this->motivationParams($managerId, $period)['rate_p1'] ?? config('motivation.default_parameters.rate_p1', 0));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function flag(array $query, string $key): bool
    {
        if (! array_key_exists($key, $query)) {
            return true;   // умолчание — все три фильтра включены
        }

        return in_array($query[$key], [1, '1', true, 'true', 'on'], true);
    }
}
