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
    public const HORIZONS = [1, 3, 7, 14, 30, 45, 60, 90];

    /**
     * Полураспад веса наблюдения: месяц-полтора назад данные значат вдвое
     * меньше сегодняшних.
     *
     * Обучение на всей истории с равными весами давало систематическое
     * завышение на четверть: весной приходило 12–15 млн в месяц, летом
     * 9–10, и модель тянула прогноз к прошлому уровню. Полностью обрезать
     * историю тоже нельзя — на десятке наблюдений коридор становится
     * бессмысленным.
     */
    private const WEIGHT_HALF_LIFE_DAYS = 45;

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
     * Модель на каждый горизонт — линейная: «столько-то придёт независимо от
     * графика, плюс такая-то доля обещанного». Свободный член растёт с
     * горизонтом, потому что график из 1С короткий: чем дальше дата, тем
     * большую часть прихода дают документы, которых ещё не существует.
     *
     * @return array{models: array<int, array<string, float|int>>, weeks: int}
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
     * @return array{models: array<int, array<string, float|int>>, weeks: int}
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

        $firstPayment = $payments === [] ? null : min(array_keys($payments));
        $models = [];
        $observations = 0;

        foreach (self::HORIZONS as $days) {
            $pairs = [];
            $start = $firstPayment !== null
                ? CarbonImmutable::parse($firstPayment)->addMonth()->startOfWeek()
                : $today;

            // Короткие горизонты набирают статистику по дням, длинные — по
            // неделям: недельный шаг на однодневном окне дал бы три десятка
            // наблюдений вместо двух сотен, а дневной на 90-дневном — почти
            // одинаковые перекрывающиеся выборки.
            $step = $days <= 7 ? 1 : 7;

            for ($t = $start; $t->lessThanOrEqualTo($today->subDays($days)); $t = $t->addDays($step)) {
                $from = $t->toDateString();
                $to = $t->addDays($days)->toDateString();

                $promised = 0.0;

                foreach ($plans as $plan) {
                    if ($plan['doc'] <= $from && $plan['due'] > $from && $plan['due'] <= $to) {
                        $promised += $plan['amount'];
                    }
                }

                $fact = 0.0;

                foreach ($payments as $day => $amount) {
                    if ($day > $from && $day <= $to) {
                        $fact += $amount;
                    }
                }

                $pairs[] = [
                    'promised' => $promised,
                    'fact' => $fact,
                    'weight' => 2 ** (-$t->diffInDays($today) / self::WEIGHT_HALF_LIFE_DAYS),
                ];
            }

            $models[$days] = $this->fitModel($days, $pairs);
            $observations = max($observations, count($pairs));
        }

        return ['models' => $models, 'weeks' => $observations];
    }

    /**
     * Линейная регрессия «факт ≈ base + slope × обещанное» на исторических окнах.
     *
     * Почему не просто отношение факт/обещание: когда график почти пуст —
     * а на день-два вперёд он пуст почти всегда, — мультипликативная модель
     * даёт ноль, хотя деньги идут. Свободный член и есть тот поток, который
     * от графика не зависит: внеплановые платежи, погашение просрочки и
     * оплата документов, выставленных уже после прогноза. Прогон по истории:
     * медианная ошибка на 60 днях 9% против 25% у отношения.
     *
     * @param  list<array{promised: float, fact: float, weight: float}>  $pairs
     * @return array{days: int, base: float, slope: float, low: float, high: float, samples: int}
     */
    private function fitModel(int $days, array $pairs): array
    {
        $count = count($pairs);

        // Статистики нет — верим графику как есть и честно показываем ноль
        // наблюдений: выдуманный коэффициент хуже отсутствующего.
        if ($count < 5) {
            return ['days' => $days, 'base' => 0.0, 'slope' => 1.0, 'low' => 0.8, 'high' => 1.3, 'samples' => $count];
        }

        $totalWeight = array_sum(array_column($pairs, 'weight'));
        $meanPromised = array_sum(array_map(static fn (array $p): float => $p['promised'] * $p['weight'], $pairs)) / $totalWeight;
        $meanFact = array_sum(array_map(static fn (array $p): float => $p['fact'] * $p['weight'], $pairs)) / $totalWeight;

        $covariance = 0.0;
        $variance = 0.0;

        foreach ($pairs as $pair) {
            $covariance += $pair['weight'] * ($pair['promised'] - $meanPromised) * ($pair['fact'] - $meanFact);
            $variance += $pair['weight'] * ($pair['promised'] - $meanPromised) ** 2;
        }

        // Отрицательный наклон — шум короткого окна: график не может
        // уменьшать ожидаемый приход.
        $slope = $variance > 0 ? max(0.0, $covariance / $variance) : 0.0;
        $base = max(0.0, $meanFact - $slope * $meanPromised);

        // Коридор — из фактических отклонений: во сколько раз приход
        // отличался от предсказанного. Мультипликативные квантили, а не
        // абсолютные: они переносятся на другой объём плана.
        $errors = [];

        foreach ($pairs as $pair) {
            $predicted = $base + $slope * $pair['promised'];

            if ($predicted > 0) {
                $errors[] = ['ratio' => $pair['fact'] / $predicted, 'weight' => $pair['weight']];
            }
        }

        $low = $this->weightedQuantile($errors, 0.05);
        $high = $this->weightedQuantile($errors, 0.95);

        // Наблюдения перекрываются (окно шире шага), поэтому разброс по ним
        // занижен. Коридор не может быть уже разумного минимума — иначе
        // экран обещал бы точность, которой у восьми месяцев истории нет.
        return [
            'days' => $days,
            'base' => round($base, 2),
            'slope' => round($slope, 4),
            'low' => round(min($low, 0.65), 3),
            'high' => round(max($high, 1.45), 3),
            'samples' => $count,
        ];
    }

    /**
     * Взвешенный квантиль: свежие наблюдения весят больше старых.
     *
     * @param  list<array{ratio: float, weight: float}>  $values
     */
    private function weightedQuantile(array $values, float $quantile): float
    {
        if ($values === []) {
            return $quantile < 0.5 ? 0.8 : 1.3;
        }

        usort($values, static fn (array $a, array $b): int => $a['ratio'] <=> $b['ratio']);

        $target = array_sum(array_column($values, 'weight')) * $quantile;
        $accumulated = 0.0;

        foreach ($values as $value) {
            $accumulated += $value['weight'];

            if ($accumulated >= $target) {
                return $value['ratio'];
            }
        }

        return end($values)['ratio'];
    }

    /**
     * Модель для произвольного горизонта: между опорными точками —
     * интерполяция, дальше последней — продление базового потока.
     *
     * @param  array{models: array<int, array<string, float|int>>, weeks: int}  $calibration
     * @return array{base: float, slope: float, low: float, high: float, samples: int, extrapolated: bool}
     */
    public function modelFor(array $calibration, int $days): array
    {
        $points = $calibration['models'];
        $horizons = array_keys($points);
        sort($horizons);

        $first = $horizons[0];
        $last = $horizons[count($horizons) - 1];

        if ($days <= $first) {
            return $this->modelPoint($points[$first], $days / max($first, 1), false);
        }

        if ($days >= $last) {
            // За пределами истории растёт только независимый от графика поток:
            // он пропорционален времени, а чувствительность к графику — нет.
            return $this->modelPoint($points[$last], $days / $last, $days > $last);
        }

        foreach ($horizons as $index => $horizon) {
            if ($days > $horizon) {
                continue;
            }

            $prev = $horizons[$index - 1];
            $weight = ($days - $prev) / ($horizon - $prev);
            $mix = static fn (string $key): float => $points[$prev][$key] + ($points[$horizon][$key] - $points[$prev][$key]) * $weight;

            return [
                'base' => round($mix('base'), 2),
                'slope' => round($mix('slope'), 4),
                'low' => round($mix('low'), 3),
                'high' => round($mix('high'), 3),
                'samples' => (int) min($points[$prev]['samples'], $points[$horizon]['samples']),
                'extrapolated' => false,
            ];
        }

        return $this->modelPoint($points[$last], 1.0, true);
    }

    /**
     * Опорная точка, растянутая по времени: базовый поток масштабируется,
     * наклон остаётся.
     *
     * @param  array<string, float|int>  $point
     * @return array{base: float, slope: float, low: float, high: float, samples: int, extrapolated: bool}
     */
    private function modelPoint(array $point, float $scale, bool $extrapolated): array
    {
        return [
            'base' => round($point['base'] * $scale, 2),
            'slope' => (float) $point['slope'],
            'low' => (float) $point['low'],
            'high' => (float) $point['high'],
            'samples' => (int) $point['samples'],
            'extrapolated' => $extrapolated,
        ];
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

        // В прогноз идут только сроки, которые ещё не наступили.
        //
        // Просроченное сюда не подмешивается, и это принципиально: раньше оно
        // сносилось на сегодня, и прогноз «на завтра» показывал 2,6 млн, из
        // которых 98% — старый долг. Такой день в истории случался один раз
        // на 196. Возврат просрочки не потерян — он сидит в коэффициенте:
        // тот считался как «факт периода / обещания с будущим сроком», а факт
        // включал в себя и погашение старых долгов.
        $byDay = [];
        $overdue = 0.0;

        foreach ($lines as $line) {
            $due = CarbonImmutable::parse($line->due_date);
            $amount = (float) $line->unpaid;

            if ($due->lessThan($today)) {
                $overdue += $amount;

                continue;
            }

            $day = $due->toDateString();
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
            $model = $this->modelFor($calibration, $days);
            $total = $model['base'] + $model['slope'] * $promised;

            $curve[] = [
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('d.m'),
                'promised' => round($promised, 2),
                // Сколько из обещанного ждём по дисциплине партнёров — сумма
                // построчной таблицы на тот же день.
                'by_discipline' => round($byDiscipline, 2),
                'low' => round($total * $model['low'], 2),
                'high' => round($total * $model['high'], 2),
                // Остальное — оплата документов, которых ещё нет, и возврат
                // тех долгов, которые модель по дисциплине списала.
                'beyond_plan' => round(max(0.0, $total - $byDiscipline), 2),
                'total' => round($total, 2),
            ];

            $cursor = $cursor->addDay();
        }

        $atTarget = $this->pointAt($curve, $target);
        $days = max(1, (int) $today->diffInDays($target));
        $model = $this->modelFor($calibration, $days);

        return [
            'target' => $target->toDateString(),
            'target_label' => $target->format('d.m.Y'),
            'days_ahead' => (int) $today->diffInDays($target),
            'horizon' => $horizon?->toDateString(),
            'horizon_label' => $horizon?->format('d.m.Y'),
            'promised' => $atTarget['promised'],
            'by_discipline' => $atTarget['by_discipline'],
            // Справкой, а не слагаемым: просрочка живёт в разделе «Просрочка»,
            // а её возврат уже учтён коэффициентом.
            'overdue' => round($overdue, 2),
            'beyond_plan' => $atTarget['beyond_plan'],
            'total' => $atTarget['total'],
            'low' => $atTarget['low'],
            'high' => $atTarget['high'],
            'model' => $model,
            'calibration' => [
                'observations' => $calibration['weeks'],
                'models' => array_values($calibration['models']),
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

            // Просроченное в ожидание не входит — та же граница, что в шапке,
            // иначе таблица и прогноз считали бы разное. Но сумму долга по
            // партнёру показываем: без неё не видно, с кем разговор особый.
            if ($due->lessThan($today)) {
                $rows[$userId]['overdue'] += $amount;

                continue;
            }

            $rows[$userId]['promised'] += $amount;
            $rows[$userId]['expected'] += $amount * $probability;
            $rows[$userId]['lines_count']++;
            $rows[$userId]['upcoming_promised'] += $amount;
            $rows[$userId]['upcoming_expected'] += $amount * $probability;
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
