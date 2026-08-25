<?php

namespace App\Services\Crm\Finance;

use App\Models\SettlementEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Прогноз поступлений: сколько денег придёт к выбранной дате и насколько
 * этому можно верить.
 *
 * Раздел отвечает на вопрос финансового директора «я верстаю бюджет, сколько
 * дашь на такое-то число», и потому считает не сумму графика, а ожидание —
 * график, взвешенный на то, как партнёры платят на самом деле.
 *
 * Прогноз собирается из двух частей, которые нельзя смешивать:
 *
 * 1. **Подтверждённая графиком** — открытые плановые строки регистра. 1С
 *    отдаёт график примерно на месяц вперёд, дальше строк просто нет.
 * 2. **По ритму** — за горизонтом графика: партнёры продолжат отгружаться и
 *    платить, и историческая скорость поступлений — единственное, что об этом
 *    известно. Без этой части ответ на «сколько к концу квартала» был бы
 *    заведомо заниженным, а с ней он честно помечен как оценка.
 *
 * Вероятности не выдуманы: границы «консервативно/оптимистично» берутся из
 * фактического разброса собираемости за последние месяцы (факт месяца против
 * плана месяца), а базовые вероятности классов — из наблюдаемого поведения
 * партнёра: платит ли он сейчас и нарушает ли уже сроки.
 */
class CollectionForecastService
{
    /**
     * Классы платёжной дисциплины: базовая вероятность получить деньги
     * по строке, срок которой наступает.
     *
     * Пороги те же, что в разделе «Просрочка»: месяц тишины — это уже не
     * задержка, а остановка платежей.
     */
    public const DISCIPLINE = [
        'reliable' => ['label' => 'платит вовремя', 'probability' => 0.95, 'palette' => 'green'],
        'slipping' => ['label' => 'платит с задержкой', 'probability' => 0.75, 'palette' => 'yellow'],
        'fading' => ['label' => 'платежи затухают', 'probability' => 0.40, 'palette' => 'orange'],
        'silent' => ['label' => 'не платит', 'probability' => 0.15, 'palette' => 'red'],
    ];

    /**
     * Затухание по возрасту просрочки: чем дольше строка не оплачена, тем
     * меньше шансов, что её закроют именно сейчас.
     *
     * @var array<int, array{days: int, factor: float}>
     */
    public const DECAY = [
        ['days' => 30, 'factor' => 0.80],
        ['days' => 90, 'factor' => 0.50],
        ['days' => PHP_INT_MAX, 'factor' => 0.25],
    ];

    /** Сколько последних полных месяцев берём для калибровки границ. */
    private const CALIBRATION_MONTHS = 6;

    /**
     * Платёжная дисциплина партнёров: класс, последний платёж, есть ли
     * просрочка.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<int, array<string, mixed>>
     */
    public function discipline(EloquentBuilder $clients, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $lastPayments = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('type', SettlementEntry::TYPE_PAYMENT_IN)
            ->whereIn('user_id', (clone $clients))
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(date) as last_payment_date')
            ->pluck('last_payment_date', 'user_id');

        $overdue = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_PLAN)
            ->whereIn('user_id', (clone $clients))
            ->whereDate('date', '<', $today->toDateString())
            ->whereRaw('amount - settled_amount > '.SettlementEntry::EPSILON)
            ->where(function ($query): void {
                $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order');
            })
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount - settled_amount) as overdue')
            ->pluck('overdue', 'user_id');

        $result = [];

        foreach ((clone $clients)->pluck('users.id') as $userId) {
            $last = $lastPayments[$userId] ?? null;
            $days = $last !== null ? (int) CarbonImmutable::parse($last)->diffInDays($today) : null;
            $hasOverdue = (float) ($overdue[$userId] ?? 0) > SettlementEntry::EPSILON;

            $key = match (true) {
                $days === null || $days > 90 => 'silent',
                $days > 30 => 'fading',
                $hasOverdue => 'slipping',
                default => 'reliable',
            };

            $result[(int) $userId] = [
                'key' => $key,
                'last_payment_date' => $last !== null ? CarbonImmutable::parse($last)->format('d.m.Y') : null,
                'days_since_payment' => $days,
                'has_overdue' => $hasOverdue,
                ...self::DISCIPLINE[$key],
            ];
        }

        return $result;
    }

    /**
     * Калибровка границ по истории: во сколько раз факт месяца отличался
     * от плана месяца.
     *
     * Считается на агрегате, а не по строкам: даты фактического погашения
     * плановой строки регистр не отдаёт, а помесячное сопоставление
     * «сколько собирались получить / сколько получили» ей и не требует.
     *
     * @return array{low: float, mid: float, high: float, months: int, samples: list<array{month: string, plan: float, fact: float, rate: float}>}
     */
    public function collectionStats(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        // Текущий месяц не берём: он не закончился, и его собираемость всегда
        // выглядит провальной.
        $from = $today->startOfMonth()->subMonths(self::CALIBRATION_MONTHS);
        $to = $today->startOfMonth()->subDay();

        $plan = $this->monthlySums(
            DB::table('settlement_entries')
                ->where('nature', SettlementEntry::NATURE_PLAN)
                ->where(function ($query): void {
                    $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order');
                }),
            'amount',
            $from,
            $to,
        );

        $fact = $this->monthlySums(
            DB::table('settlement_entries')
                ->where('nature', SettlementEntry::NATURE_FACT)
                ->where('type', SettlementEntry::TYPE_PAYMENT_IN),
            'COALESCE(amount_rub, amount)',
            $from,
            $to,
        );

        $samples = [];

        foreach ($plan as $month => $planned) {
            // Месяцы без плана пропускаем: делить на ноль нечем, а сам факт
            // прихода без плана уже учтён в ритме.
            if ($planned <= 0) {
                continue;
            }

            $samples[] = [
                'month' => $month,
                'plan' => round($planned, 2),
                'fact' => round($fact[$month] ?? 0, 2),
                'rate' => round(($fact[$month] ?? 0) / $planned, 4),
            ];
        }

        $rates = array_column($samples, 'rate');
        sort($rates);

        // Истории мало — границы берём консервативные по умолчанию, но
        // помечаем, на скольких месяцах они посчитаны: экран обязан показать,
        // что уверенность в цифре тоже оценка.
        if (count($rates) < 3) {
            return ['low' => 0.80, 'mid' => 0.95, 'high' => 1.10, 'months' => count($rates), 'samples' => $samples];
        }

        return [
            'low' => round($rates[0], 4),
            'mid' => round($rates[(int) floor(count($rates) / 2)], 4),
            'high' => round($rates[count($rates) - 1], 4),
            'months' => count($rates),
            'samples' => $samples,
        ];
    }

    /**
     * Скорость поступлений: сколько в среднем приходит в день.
     *
     * Медиана по последним полным месяцам, а не среднее: один крупный расчёт
     * иначе задирает прогноз на весь квартал вперёд.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function dailyRhythm(EloquentBuilder $clients, ?CarbonImmutable $today = null): float
    {
        $today ??= CarbonImmutable::today();
        $from = $today->startOfMonth()->subMonths(3);
        $to = $today->startOfMonth()->subDay();

        $months = $this->monthlySums(
            DB::table('settlement_entries')
                ->where('nature', SettlementEntry::NATURE_FACT)
                ->where('type', SettlementEntry::TYPE_PAYMENT_IN)
                ->whereIn('user_id', (clone $clients)),
            'COALESCE(amount_rub, amount)',
            $from,
            $to,
        );

        $values = array_values(array_filter($months, static fn (float $value): bool => $value > 0));

        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $median = $values[(int) floor(count($values) / 2)];

        return round($median / 30, 2);
    }

    /**
     * Прогноз к дате: сколько ожидаем, в каких границах и из чего это состоит.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function forecast(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $target,
        ?CarbonImmutable $today = null,
    ): array {
        $today ??= CarbonImmutable::today();
        $discipline = $this->discipline($clients, $today);
        $stats = $this->collectionStats($today);
        $rhythm = $this->dailyRhythm($clients, $today);

        $lines = $this->openPlanLines($clients, $filters);
        $horizon = $this->planHorizon($lines);

        // Накопление по дням: к каждой дате — сколько по графику обещано и
        // сколько из этого реально ожидаем.
        $byDay = [];

        foreach ($lines as $line) {
            $due = CarbonImmutable::parse($line->due_date);
            // Просроченное не остаётся в своём прошлом: деньги ждут сегодня,
            // и в кривую оно входит первым днём, иначе график начинался бы
            // с нуля при живом долге.
            $day = $due->lessThan($today) ? $today : $due;
            $probability = $this->lineProbability($line, $discipline, $today);
            $amount = (float) $line->unpaid;

            $key = $day->toDateString();
            $byDay[$key] ??= ['promised' => 0.0, 'expected' => 0.0, 'overdue' => 0.0];
            $byDay[$key]['promised'] += $amount;
            $byDay[$key]['expected'] += $amount * $probability;

            if ($due->lessThan($today)) {
                $byDay[$key]['overdue'] += $amount;
            }
        }

        ksort($byDay);

        // Ритм будущих отгрузок: исторически в месяц приходит больше, чем
        // обещает текущий график, — разница и есть оплата документов, которых
        // ещё нет. Считаем её как остаток месячного прихода сверх того, что
        // даёт открытый план за те же 30 дней: так оценка самокалибруется и
        // не задваивает уже обещанное.
        $expectedInMonth = 0.0;
        $monthEnd = $today->addDays(30);

        foreach ($byDay as $date => $sums) {
            if ($date <= $monthEnd->toDateString()) {
                $expectedInMonth += $sums['expected'];
            }
        }

        $newSalesDaily = max(0.0, ($rhythm * 30 - $expectedInMonth) / 30);

        $curve = [];
        $promised = 0.0;
        $expected = 0.0;
        $cursor = $today;
        $end = $target->greaterThan($horizon ?? $target) ? $target : ($horizon ?? $target);

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $promised += $byDay[$key]['promised'] ?? 0.0;
            $expected += $byDay[$key]['expected'] ?? 0.0;

            // До конца графика ритм добавляет только то, чего в плане нет
            // (будущие отгрузки). За горизонтом графика плана нет вовсе —
            // там прогноз держится на полной исторической скорости, иначе
            // квартал оказался бы занижен вдвое.
            $daysAhead = (int) $today->diffInDays($cursor);
            $daysWithinPlan = $horizon !== null ? min($daysAhead, (int) $today->diffInDays($horizon)) : $daysAhead;
            $daysBeyondPlan = max(0, $daysAhead - $daysWithinPlan);

            $fromNewSales = $newSalesDaily * $daysWithinPlan + $rhythm * $daysBeyondPlan;

            $curve[] = [
                'date' => $key,
                'label' => $cursor->format('d.m'),
                'promised' => round($promised, 2),
                'expected' => round($expected, 2),
                'rhythm' => round($fromNewSales, 2),
                'low' => round($expected * $this->ratio($stats, 'low') + $fromNewSales * 0.6, 2),
                'high' => round($expected * $this->ratio($stats, 'high') + $fromNewSales * 1.3, 2),
                'total' => round($expected + $fromNewSales, 2),
            ];

            $cursor = $cursor->addDay();
        }

        $atTarget = $this->pointAt($curve, $target);

        return [
            'target' => $target->toDateString(),
            'target_label' => $target->format('d.m.Y'),
            'days_ahead' => (int) $today->diffInDays($target),
            'horizon' => $horizon?->toDateString(),
            'horizon_label' => $horizon?->format('d.m.Y'),
            'promised' => $atTarget['promised'],
            'expected' => $atTarget['expected'],
            'rhythm_part' => $atTarget['rhythm'],
            'total' => $atTarget['total'],
            'low' => $atTarget['low'],
            'high' => $atTarget['high'],
            'daily_rhythm' => $rhythm,
            'new_sales_daily' => round($newSalesDaily, 2),
            'calibration' => $stats,
            'curve' => $curve,
        ];
    }

    /**
     * Разрез «от кого»: сколько ждём к дате от каждого партнёра, менеджера,
     * нашей организации или контрагента.
     *
     * Вторичный слой раздела: сначала бюджетный ответ «сколько», потом
     * «с кого спрашивать, если не придёт».
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function forecastByPartner(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $target,
        ?CarbonImmutable $today = null,
    ): array {
        $today ??= CarbonImmutable::today();
        $discipline = $this->discipline($clients, $today);

        $rows = [];

        foreach ($this->openPlanLines($clients, $filters) as $line) {
            $due = CarbonImmutable::parse($line->due_date);

            if ($due->greaterThan($target)) {
                continue;
            }

            $userId = (int) $line->user_id;
            $amount = (float) $line->unpaid;
            $probability = $this->lineProbability($line, $discipline, $today);

            $rows[$userId] ??= [
                'key' => 'u'.$userId,
                'entity_id' => $userId,
                'title' => $line->client_erp_name ?: $line->client_name,
                'subtitle' => $line->manager_name,
                'url' => route('crm.clients.show', $userId),
                'promised' => 0.0,
                'expected' => 0.0,
                'overdue' => 0.0,
                'lines_count' => 0,
                'discipline' => $discipline[$userId] ?? self::DISCIPLINE['silent'] + ['key' => 'silent'],
            ];

            $rows[$userId]['promised'] += $amount;
            $rows[$userId]['expected'] += $amount * $probability;
            $rows[$userId]['lines_count']++;

            if ($due->lessThan($today)) {
                $rows[$userId]['overdue'] += $amount;
            }
        }

        $rows = array_map(static function (array $row): array {
            $row['promised'] = round($row['promised'], 2);
            $row['expected'] = round($row['expected'], 2);
            $row['overdue'] = round($row['overdue'], 2);
            $row['probability'] = $row['promised'] > 0 ? round($row['expected'] / $row['promised'], 4) : 0.0;

            return $row;
        }, array_values($rows));

        // Сверху те, от кого ждём больше всего: раздел читают ради бюджета,
        // а не ради алфавита.
        usort($rows, static fn (array $a, array $b): int => $b['expected'] <=> $a['expected']);

        return $rows;
    }

    /**
     * Вероятность получить деньги по строке.
     *
     * База — класс дисциплины партнёра, множитель — возраст просрочки:
     * обещание на будущее и обещание, нарушенное полгода назад, не могут
     * стоить одинаково.
     *
     * @param  array<int, array<string, mixed>>  $discipline
     */
    private function lineProbability(object $line, array $discipline, CarbonImmutable $today): float
    {
        $base = (float) ($discipline[(int) $line->user_id]['probability'] ?? self::DISCIPLINE['silent']['probability']);
        $due = CarbonImmutable::parse($line->due_date);

        if ($due->greaterThanOrEqualTo($today)) {
            return $base;
        }

        $days = (int) $due->diffInDays($today);

        foreach (self::DECAY as $step) {
            if ($days <= $step['days']) {
                return round($base * $step['factor'], 4);
            }
        }

        return round($base * 0.25, 4);
    }

    /**
     * Открытые плановые строки — то, из чего складывается прогноз.
     *
     * Планы по заказам исключены той же логикой, что в просрочке: заказ это
     * намерение, деньги обещает отгрузка.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function openPlanLines(EloquentBuilder $clients, FinanceFilters $filters): \Illuminate\Support\Collection
    {
        return DB::table('settlement_entries as sch')
            ->join('users as u', 'u.id', '=', 'sch.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->where('sch.nature', SettlementEntry::NATURE_PLAN)
            ->whereIn('sch.user_id', (clone $clients))
            ->whereRaw('sch.amount - sch.settled_amount > '.SettlementEntry::EPSILON)
            ->where(function ($query): void {
                $query->whereNull('sch.document_kind')->orWhere('sch.document_kind', '<>', 'order');
            })
            ->when($filters->organizationIds !== [], fn ($query) => $query->whereIn('sch.organization_id', $filters->organizationIds))
            ->select(['sch.user_id', 'sch.date as due_date', 'u.name as client_name', 'u.erp_name as client_erp_name', 'pm.name as manager_name'])
            ->selectRaw('sch.amount - sch.settled_amount as unpaid')
            ->get();
    }

    /** Последняя плановая дата: дальше неё графика от 1С просто нет. */
    private function planHorizon(\Illuminate\Support\Collection $lines): ?CarbonImmutable
    {
        $max = $lines->max('due_date');

        return $max !== null ? CarbonImmutable::parse($max) : null;
    }

    /** Отношение границы к середине: во столько раз сценарий отличается от ожидания. */
    private function ratio(array $stats, string $bound): float
    {
        return $stats['mid'] > 0 ? $stats[$bound] / $stats['mid'] : 1.0;
    }

    /**
     * Точка кривой на дату: последняя, не превысившая её.
     *
     * @param  list<array<string, mixed>>  $curve
     * @return array<string, float>
     */
    private function pointAt(array $curve, CarbonImmutable $target): array
    {
        $point = ['promised' => 0.0, 'expected' => 0.0, 'rhythm' => 0.0, 'low' => 0.0, 'high' => 0.0, 'total' => 0.0];
        $date = $target->toDateString();

        foreach ($curve as $row) {
            if ($row['date'] > $date) {
                break;
            }

            $point = [
                'promised' => $row['promised'],
                'expected' => $row['expected'],
                'rhythm' => $row['rhythm'],
                'low' => $row['low'],
                'high' => $row['high'],
                'total' => $row['total'],
            ];
        }

        return $point;
    }

    /**
     * Помесячные суммы выражения по бизнес-дате.
     *
     * @return array<string, float> 'YYYY-MM' => сумма
     */
    private function monthlySums(
        \Illuminate\Database\Query\Builder $query,
        string $expression,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $month = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', date)"
            : "DATE_FORMAT(date, '%Y-%m')";

        $rows = $query
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->groupByRaw($month)
            ->selectRaw($month.' as month')
            ->selectRaw('SUM('.$expression.') as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->month] = (float) $row->total;
        }

        return $result;
    }
}
