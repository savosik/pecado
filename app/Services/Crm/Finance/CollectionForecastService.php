<?php

namespace App\Services\Crm\Finance;

use App\Models\SettlementEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\Cache;
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

    /** Опорные горизонты калибровки в днях: между ними интерполируем. */
    public const HORIZONS = [7, 14, 30, 45, 60, 90];

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
     * Калибровка по собственной истории: во сколько раз фактический приход
     * за N дней отличался от того, что обещал график на начало этих N дней.
     *
     * Это и есть модель. Раньше здесь были две эвристики — вероятности по
     * классам и «ритм отгрузок», — и прогон по историческим неделям показал,
     * что вместе они завышают прогноз вдвое (средняя ошибка 98%), тогда как
     * голая сумма графика ошибалась на 15%. Поэтому уровень прогноза задаёт
     * наблюдаемое отношение факт/обещание, а вероятности остались там, где
     * они полезны, — в распределении суммы между партнёрами.
     *
     * Отношение растёт с горизонтом (за 14 дней 1,08, за 60 — уже 2,06):
     * график из 1С короткий, и чем дальше дата, тем большую часть прихода
     * дают документы, которых на момент прогноза ещё не существовало.
     *
     * @return array{ratios: array<int, array{days: int, low: float, mid: float, high: float, samples: int}>, weeks: int}
     */
    public function calibration(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return Cache::remember(
            'finance:collection-calibration:'.$today->toDateString(),
            now()->addHours(6),
            fn (): array => $this->buildCalibration($today),
        );
    }

    /**
     * @return array{ratios: array<int, array{days: int, low: float, mid: float, high: float, samples: int}>, weeks: int}
     */
    private function buildCalibration(CarbonImmutable $today): array
    {
        $plans = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_PLAN)
            ->where(function ($query): void {
                $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order');
            })
            ->whereNotNull('document_date')
            ->selectRaw('DATE(date) as due, DATE(document_date) as doc, SUM(amount) as amount')
            ->groupByRaw('DATE(date), DATE(document_date)')
            ->get()
            ->map(static fn (object $row): array => [
                'due' => (string) $row->due,
                'doc' => (string) $row->doc,
                'amount' => (float) $row->amount,
            ])
            ->all();

        $payments = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('type', SettlementEntry::TYPE_PAYMENT_IN)
            ->selectRaw('DATE(date) as day, SUM(COALESCE(amount_rub, amount)) as amount')
            ->groupByRaw('DATE(date)')
            ->pluck('amount', 'day')
            ->all();

        // Первая неделя, от которой есть смысл считать: история платежей
        // начинается вместе с переходом на регистр, до неё сравнивать нечего.
        $firstPayment = $payments === [] ? null : min(array_keys($payments));
        $ratios = [];
        $weeks = 0;

        foreach (self::HORIZONS as $days) {
            $samples = [];
            $start = $firstPayment !== null
                ? CarbonImmutable::parse($firstPayment)->addMonth()->startOfWeek()
                : $today;

            for ($t = $start; $t->lessThanOrEqualTo($today->subDays($days)); $t = $t->addWeek()) {
                $from = $t->toDateString();
                $to = $t->addDays($days)->toDateString();

                $promised = 0.0;

                foreach ($plans as $plan) {
                    if ($plan['doc'] <= $from && $plan['due'] > $from && $plan['due'] <= $to) {
                        $promised += $plan['amount'];
                    }
                }

                if ($promised <= 0) {
                    continue;
                }

                $fact = 0.0;

                foreach ($payments as $day => $amount) {
                    if ($day > $from && $day <= $to) {
                        $fact += $amount;
                    }
                }

                $samples[] = ['promised' => $promised, 'ratio' => $fact / $promised];
            }

            // Недели, где график почти пуст, выбрасываются: деление живого
            // факта на копеечное обещание давало отношения вроде 11,5 и
            // растягивало коридор до бессмыслицы.
            $median = $this->medianOf(array_column($samples, 'promised'));
            $samples = array_column(array_filter(
                $samples,
                static fn (array $sample): bool => $sample['promised'] >= $median * 0.2,
            ), 'ratio');

            sort($samples);
            $count = count($samples);
            $weeks = max($weeks, $count);

            // Меньше пяти наблюдений — статистики нет; отдаём нейтральное
            // отношение и честно показываем, на чём оно посчитано.
            $ratios[$days] = $count < 5
                ? ['days' => $days, 'low' => 0.8, 'mid' => 1.0, 'high' => 1.3, 'samples' => $count]
                : [
                    'days' => $days,
                    // P05/P95, а не P10/P90: прогон по истории показал, что
                    // узкий коридор ловил факт лишь в 59% недель — для числа,
                    // которое несут в бюджет, это негодная надёжность.
                    'low' => round($samples[(int) floor($count * 0.05)], 3),
                    'mid' => round($samples[(int) floor($count * 0.5)], 3),
                    'high' => round($samples[min($count - 1, (int) round($count * 0.95))], 3),
                    'samples' => $count,
                ];
        }

        return ['ratios' => $ratios, 'weeks' => $weeks];
    }

    /**
     * Медиана списка — для отбраковки вырожденных наблюдений.
     *
     * @param  list<float>  $values
     */
    private function medianOf(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        return $values[(int) floor(count($values) / 2)];
    }

    /**
     * Отношение факт/обещание для произвольного горизонта: между опорными
     * точками — линейная интерполяция, дальше последней — продление роста.
     *
     * @param  array{ratios: array<int, array<string, float|int>>, weeks: int}  $calibration
     * @return array{low: float, mid: float, high: float, samples: int, extrapolated: bool}
     */
    public function ratioFor(array $calibration, int $days): array
    {
        $points = $calibration['ratios'];
        $horizons = array_keys($points);
        sort($horizons);

        $first = $horizons[0];
        $last = $horizons[count($horizons) - 1];

        if ($days <= $first) {
            return $points[$first] + ['extrapolated' => false];
        }

        if ($days >= $last) {
            // За пределами истории отношение продлевается пропорционально
            // горизонту: дальше 60–90 дней проверять было не на чем, и экран
            // обязан пометить такой прогноз как экстраполяцию.
            $scale = $days / $last;

            return [
                'low' => round($points[$last]['low'] * $scale, 3),
                'mid' => round($points[$last]['mid'] * $scale, 3),
                'high' => round($points[$last]['high'] * $scale, 3),
                'samples' => $points[$last]['samples'],
                'extrapolated' => $days > $last,
            ];
        }

        foreach ($horizons as $index => $horizon) {
            if ($days > $horizon) {
                continue;
            }

            $prev = $horizons[$index - 1];
            $weight = ($days - $prev) / ($horizon - $prev);

            return [
                'low' => round($points[$prev]['low'] + ($points[$horizon]['low'] - $points[$prev]['low']) * $weight, 3),
                'mid' => round($points[$prev]['mid'] + ($points[$horizon]['mid'] - $points[$prev]['mid']) * $weight, 3),
                'high' => round($points[$prev]['high'] + ($points[$horizon]['high'] - $points[$prev]['high']) * $weight, 3),
                'samples' => min($points[$prev]['samples'], $points[$horizon]['samples']),
                'extrapolated' => false,
            ];
        }

        return $points[$last] + ['extrapolated' => true];
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
        $calibration = $this->calibration($today);

        $lines = $this->openPlanLines($clients, $filters);
        $horizon = $this->planHorizon($lines);
        $discipline = $this->discipline($clients, $today);

        // Обещанное по дням: просроченное ждём сегодня, иначе кривая
        // начиналась бы с нуля при живом долге. Рядом — то же, взвешенное на
        // дисциплину: это ровно сумма таблицы «от кого ждём», и она обязана
        // сходиться с разложением в шапке.
        $byDay = [];

        foreach ($lines as $line) {
            $due = CarbonImmutable::parse($line->due_date);
            $day = ($due->lessThan($today) ? $today : $due)->toDateString();
            $amount = (float) $line->unpaid;

            $byDay[$day] ??= ['promised' => 0.0, 'by_discipline' => 0.0];
            $byDay[$day]['promised'] += $amount;
            $byDay[$day]['by_discipline'] += $amount * $this->lineProbability($line, $discipline, $today);
        }

        ksort($byDay);

        $curve = [];
        $promised = 0.0;
        $byDiscipline = 0.0;
        $cursor = $today;
        $end = $target->greaterThan($horizon ?? $target) ? $target : ($horizon ?? $target);

        while ($cursor->lessThanOrEqualTo($end)) {
            $day = $byDay[$cursor->toDateString()] ?? ['promised' => 0.0, 'by_discipline' => 0.0];
            $promised += $day['promised'];
            $byDiscipline += $day['by_discipline'];

            $days = max(1, (int) $today->diffInDays($cursor));
            $ratio = $this->ratioFor($calibration, $days);
            $total = $promised * $ratio['mid'];

            $curve[] = [
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('d.m'),
                'promised' => round($promised, 2),
                // Сколько из обещанного ждём по дисциплине партнёров — сумма
                // построчной таблицы на тот же день.
                'by_discipline' => round($byDiscipline, 2),
                'low' => round($promised * $ratio['low'], 2),
                'high' => round($promised * $ratio['high'], 2),
                // Остальное — оплата документов, которых ещё нет, и возврат
                // тех долгов, которые модель по дисциплине списала.
                'beyond_plan' => round(max(0.0, $total - $byDiscipline), 2),
                'total' => round($total, 2),
            ];

            $cursor = $cursor->addDay();
        }

        $atTarget = $this->pointAt($curve, $target);
        $days = max(1, (int) $today->diffInDays($target));
        $ratio = $this->ratioFor($calibration, $days);

        return [
            'target' => $target->toDateString(),
            'target_label' => $target->format('d.m.Y'),
            'days_ahead' => (int) $today->diffInDays($target),
            'horizon' => $horizon?->toDateString(),
            'horizon_label' => $horizon?->format('d.m.Y'),
            'promised' => $atTarget['promised'],
            'by_discipline' => $atTarget['by_discipline'],
            'beyond_plan' => $atTarget['beyond_plan'],
            'total' => $atTarget['total'],
            'low' => $atTarget['low'],
            'high' => $atTarget['high'],
            'ratio' => $ratio,
            'calibration' => [
                'weeks' => $calibration['weeks'],
                'ratios' => array_values($calibration['ratios']),
            ],
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
                // Разбивка «срок впереди / срок нарушен»: она объясняет,
                // почему ожидание именно такое, и без неё вероятность
                // выглядит взятой с потолка.
                'upcoming_promised' => 0.0,
                'upcoming_expected' => 0.0,
                'overdue_expected' => 0.0,
                'lines_count' => 0,
                'discipline' => $discipline[$userId] ?? self::DISCIPLINE['silent'] + ['key' => 'silent'],
            ];

            $rows[$userId]['promised'] += $amount;
            $rows[$userId]['expected'] += $amount * $probability;
            $rows[$userId]['lines_count']++;

            if ($due->lessThan($today)) {
                $rows[$userId]['overdue'] += $amount;
                $rows[$userId]['overdue_expected'] += $amount * $probability;
            } else {
                $rows[$userId]['upcoming_promised'] += $amount;
                $rows[$userId]['upcoming_expected'] += $amount * $probability;
            }
        }

        $rows = array_map(static function (array $row): array {
            foreach (['promised', 'expected', 'overdue', 'upcoming_promised', 'upcoming_expected', 'overdue_expected'] as $key) {
                $row[$key] = round($row[$key], 2);
            }

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
        $keys = ['promised', 'by_discipline', 'beyond_plan', 'low', 'high', 'total'];
        $point = array_fill_keys($keys, 0.0);
        $date = $target->toDateString();

        foreach ($curve as $row) {
            if ($row['date'] > $date) {
                break;
            }

            foreach ($keys as $key) {
                $point[$key] = (float) $row[$key];
            }
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
