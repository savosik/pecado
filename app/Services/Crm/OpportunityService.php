<?php

namespace App\Services\Crm;

use App\Enums\Crm\OpportunityPreset;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\GapAnalysisService;
use App\Services\Analytics\ShipmentAnalyticsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Возможности: кому звонить сегодня и почему.
 *
 * Отставание от плана само по себе — цифра, а не работа. Этот сервис превращает
 * её в упорядоченный список клиентов, где у каждой строки написана причина
 * попадания. Список без причины менеджер не примет; с причиной — проверит.
 *
 * Своих запросов к отгрузкам здесь нет. Факт и обороты берутся из
 * {@see ShipmentAnalyticsService} (тот же движок, что считает /crm/analytics),
 * план и факт месяца — из {@see ClientPlanFactService}, поиск «кто не берёт X» —
 * из {@see GapAnalysisService}. Второй расчёт продаж запрещён принципом роадмапа:
 * расхождение цифр между экранами CRM — баг по определению.
 *
 * Ранжирование общее для всех пресетов: пресет отбирает кандидатов, порядок
 * задаёт одна и та же взвешенная оценка. Веса — в `config/crm.opportunities`,
 * потому что это вопрос управления отделом, а не разработки.
 */
class OpportunityService
{
    /** Классы ABC в оценке: A-клиент важнее C, но класс — не главный сигнал. */
    private const ABC_SCORE = ['A' => 1.0, 'B' => 0.6, 'C' => 0.3];

    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly GapAnalysisService $gap,
        private readonly ClientPlanFactService $planFact,
    ) {}

    /**
     * Список возможностей по пресету, отсортированный по оценке.
     *
     * @param  array{dimension?: string|null, value?: int|null, label?: string|null}  $params
     *                                                                                         параметры пресета «не берут X»
     * @return array{
     *   preset: string,
     *   rows: list<array<string, mixed>>,
     *   summary: array{candidates: int, matched: int, gap_total: float},
     *   needs_dimension: bool
     * }
     */
    public function rank(
        CarbonInterface $month,
        PlanScope $scope,
        OpportunityPreset $preset,
        array $params = [],
        ?int $limit = null,
    ): array {
        $dataset = $this->dataset($month, $scope);
        $limit ??= (int) config('crm.opportunities.limit', 100);

        $matched = $this->applyPreset($dataset, $preset, $month, $scope, $params);
        $rows = $this->score($matched, $preset, $params);

        return [
            'preset' => $preset->value,
            'needs_dimension' => $preset->needsDimension(),
            'rows' => array_slice($rows, 0, $limit),
            'summary' => [
                'candidates' => count($dataset),
                'matched' => count($rows),
                'gap_total' => round(array_sum(array_map(
                    fn (array $row): float => (float) ($row['lag'] ?? 0),
                    $rows,
                )), 2),
            ],
        ];
    }

    /**
     * Топ самых горячих строк — для виджета на рабочем столе.
     *
     * Пресет здесь не спрашивается намеренно: на дашборде нужен ответ «что делать
     * сейчас», а не выбор среза. Кандидат — тот, у кого сработал хоть один сигнал.
     *
     * @return list<array<string, mixed>>
     */
    public function top(CarbonInterface $month, PlanScope $scope, int $limit = 5): array
    {
        $threshold = (int) config('crm.opportunities.drop_threshold_percent', 25);

        $matched = array_filter(
            $this->dataset($month, $scope),
            fn (array $row): bool => ($row['lag'] ?? 0) > 0
                || ($row['overdue_days'] ?? 0) > 0
                || ($row['drop_percent'] ?? 0) >= $threshold,
        );

        return array_slice($this->score($matched, OpportunityPreset::PLAN_LAG, []), 0, $limit);
    }

    /**
     * Сигналы по каждому клиенту скоупа, без отбора и ранжирования.
     *
     * Открыто для витрин, которым нужен не список «кому звонить», а состояние
     * всей базы разом — «грядки» (crm-08) рисуют по нему плитки. Отдаётся тот же
     * кэшированный набор, что питает пресеты: вторая копия расчёта означала бы,
     * что клиент спит на одном экране и не спит на другом.
     *
     * @return array<int, array<string, mixed>> ключ — идентификатор клиента
     */
    public function signals(CarbonInterface $month, PlanScope $scope): array
    {
        return $this->dataset($month, $scope);
    }

    /**
     * Сигналы по каждому клиенту скоупа. Кэшируется по месяцу и составу скоупа:
     * пресеты переключаются поверх одного и того же набора, и пересчитывать
     * агрегаты на каждое нажатие незачем.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dataset(CarbonInterface $month, PlanScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $key = 'crm:opportunities:'
            .CarbonImmutable::instance($month)->format('Y-m').':'
            .$scope->cacheKey();

        return Cache::remember(
            $key,
            (int) config('crm.opportunities.cache_ttl', 900),
            fn (): array => $this->buildDataset($month, $scope),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDataset(CarbonInterface $month, PlanScope $scope): array
    {
        $ids = $scope->clientIds;
        $ctx = AnalyticsContext::forScope($ids, AnalyticsContext::DATE_ERP, null);

        $start = CarbonImmutable::instance($month)->startOfMonth();
        $today = CarbonImmutable::now()->startOfDay();

        $year = $this->partnerAmounts($ctx, $this->filters($start->subMonthsNoOverflow(11), $start->endOfMonth()), count($ids));
        $previous = $this->partnerAmounts($ctx, $this->filters($start->subMonthNoOverflow(), $start->subMonthNoOverflow()->endOfMonth()), count($ids));

        $planFact = $this->planFact->forClients($ids, $month);
        $lastPurchase = $this->gap->lastPurchaseMap($ctx, 'partner');
        $meta = $this->clientMeta($ids);

        $abc = $this->abcClasses($year);
        $defaultCycle = (int) config('crm.opportunities.default_cycle_days', 45);

        $rows = [];

        foreach ($ids as $id) {
            $info = $meta[$id] ?? null;

            if ($info === null) {
                continue;   // клиент исчез между выборкой скоупа и расчётом
            }

            // forClients отдаёт строку на каждый переданный id — обращаемся прямо.
            $plan = $planFact[$id]['plan'];
            $fact = (float) $planFact[$id]['fact'];
            $prev = (float) ($previous[$id]['amount'] ?? 0.0);

            $yearAmount = (float) ($year[$id]['amount'] ?? 0.0);
            $yearShipments = (int) ($year[$id]['shipments'] ?? 0);

            $lastAt = $lastPurchase[(string) $id] ?? null;
            $daysSince = $lastAt !== null
                ? (int) CarbonImmutable::parse($lastAt)->startOfDay()->diffInDays($today)
                : null;

            $cycle = $info['order_cycle_days'] ?: $defaultCycle;

            $rows[$id] = [
                'id' => $id,
                'name' => $info['name'],
                'email' => $info['email'],
                'phone' => $info['phone'],
                'manager_id' => $info['manager_id'],
                'manager' => $info['manager'],

                'plan' => $plan,
                'fact' => round($fact, 2),
                'percent' => $planFact[$id]['percent'],
                'lag' => $plan !== null ? round(max(0.0, $plan - $fact), 2) : null,

                'previous_amount' => round($prev, 2),
                // Падение считаем только от ненулевой базы: рост с нуля процентом
                // не выражается, и «просел на 100%» после единственной отгрузки —
                // не сигнал, а артефакт деления.
                'drop_percent' => $prev > 0 && $fact < $prev ? (int) round(($prev - $fact) / $prev * 100) : null,

                'year_amount' => round($yearAmount, 2),
                'shipments_year' => $yearShipments,
                'avg_check' => $yearShipments > 0 ? round($yearAmount / $yearShipments, 2) : null,
                'abc' => $abc[$id] ?? null,

                'last_purchase_at' => $lastAt,
                'days_since' => $daysSince,
                'cycle_days' => $cycle,
                'has_own_cycle' => $info['order_cycle_days'] !== null,
                'overdue_days' => $daysSince !== null ? max(0, $daysSince - $cycle) : null,

                'registered_days' => $info['created_at'] !== null
                    ? (int) CarbonImmutable::parse($info['created_at'])->startOfDay()->diffInDays($today)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Отбор кандидатов пресетом.
     *
     * @param  array<int, array<string, mixed>>  $dataset
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private function applyPreset(
        array $dataset,
        OpportunityPreset $preset,
        CarbonInterface $month,
        PlanScope $scope,
        array $params,
    ): array {
        $dropThreshold = (int) config('crm.opportunities.drop_threshold_percent', 25);
        $minAge = (int) config('crm.opportunities.never_bought_min_age_days', 60);

        return match ($preset) {
            OpportunityPreset::PLAN_LAG => array_filter(
                $dataset,
                fn (array $row): bool => $row['plan'] !== null && $row['lag'] > 0,
            ),

            OpportunityPreset::SLEEPING => $this->sleeping($dataset),

            OpportunityPreset::DECLINING => array_filter(
                $dataset,
                fn (array $row): bool => $row['drop_percent'] !== null && $row['drop_percent'] >= $dropThreshold,
            ),

            // Клиент, заведённый на прошлой неделе, ещё не «ни разу не купил» —
            // он просто не успел. Без порога возраста пресет наполовину состоял бы
            // из таких строк (на проде без отгрузок ~86 % базы).
            OpportunityPreset::NEVER_BOUGHT => array_filter(
                $dataset,
                fn (array $row): bool => $row['last_purchase_at'] === null
                    && ($row['registered_days'] === null || $row['registered_days'] >= $minAge),
            ),

            OpportunityPreset::NOT_BUYING => $this->notBuying($dataset, $month, $scope, $params),
        };
    }

    /**
     * Спящие с высоким чеком: не отгружались дольше своего цикла, чек выше медианы.
     *
     * Медиана считается по клиентам с покупками за год — сравнивать чек спящего
     * с нулями тех, кто не покупал вовсе, бессмысленно.
     *
     * @param  array<int, array<string, mixed>>  $dataset
     * @return array<int, array<string, mixed>>
     */
    private function sleeping(array $dataset): array
    {
        $checks = array_values(array_filter(array_map(
            fn (array $row): ?float => $row['avg_check'],
            $dataset,
        )));

        $median = $this->median($checks);

        return array_filter(
            $dataset,
            fn (array $row): bool => $row['overdue_days'] !== null
                && $row['overdue_days'] > 0
                && $row['avg_check'] !== null
                && $row['avg_check'] >= $median,
        );
    }

    /**
     * «Не берут бренд/категорию» — поиск целиком отдан {@see GapAnalysisService}:
     * разность множеств «покупают у нас» и «покупали X» там уже написана и уже
     * работает на экране gap-анализа. Здесь только пересечение с датасетом.
     *
     * @param  array<int, array<string, mixed>>  $dataset
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private function notBuying(array $dataset, CarbonInterface $month, PlanScope $scope, array $params): array
    {
        $dimension = $params['dimension'] ?? null;
        $value = (int) ($params['value'] ?? 0);

        if ($dimension === null || $value <= 0 || $scope->isEmpty()) {
            return [];
        }

        $start = CarbonImmutable::instance($month)->startOfMonth();

        $result = $this->gap->analyze(
            AnalyticsContext::forScope($scope->clientIds, AnalyticsContext::DATE_ERP, null),
            [
                'subject' => 'partner',
                'exclude_dimension' => $dimension,
                'exclude_value' => $value,
                'exclude_window' => 'all',
                'exclude_months' => 12,
                'include_dimension' => null,
                'include_value' => null,
                // Спящих не подмешиваем: вопрос пресета — «покупают у нас, но это
                // не берут», а не «кто вообще числится за менеджером».
                'include_dormant' => false,
            ],
            $start->subMonthsNoOverflow(11),
            $start->endOfMonth(),
        );

        $allowed = array_flip(array_filter(array_column($result['rows'], 'id')));

        return array_filter(
            $dataset,
            fn (array $row): bool => isset($allowed[$row['id']]),
        );
    }

    /**
     * Оценка и человекочитаемое объяснение для каждой строки.
     *
     * Нормировка идёт по отобранному набору, а не по всей базе: оценка нужна
     * только для порядка внутри списка, и абсолютной величины у неё нет.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function score(array $rows, OpportunityPreset $preset, array $params): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var array<string, float> $weights */
        $weights = config('crm.opportunities.weights', []);
        $dropThreshold = (int) config('crm.opportunities.drop_threshold_percent', 25);

        $maxLag = max(array_map(fn (array $r): float => (float) ($r['lag'] ?? 0), $rows));
        $maxCheck = max(array_map(fn (array $r): float => (float) ($r['avg_check'] ?? 0), $rows));

        $scored = [];

        foreach ($rows as $row) {
            $lag = (float) ($row['lag'] ?? 0);
            $check = (float) ($row['avg_check'] ?? 0);
            $overdue = (int) ($row['overdue_days'] ?? 0);
            $cycle = max(1, (int) $row['cycle_days']);
            $drop = (int) ($row['drop_percent'] ?? 0);

            $signals = [
                'plan_gap' => $maxLag > 0 ? $lag / $maxLag : 0.0,
                // Просрочка в один полный цикл — уже максимум: клиент, пропустивший
                // три цикла, не втрое важнее пропустившего один.
                'overdue' => min(1.0, $overdue / $cycle),
                'avg_check' => $maxCheck > 0 ? $check / $maxCheck : 0.0,
                'drop' => min(1.0, $drop / 100),
                'abc' => self::ABC_SCORE[$row['abc']] ?? 0.0,
            ];

            $score = 0.0;
            foreach ($signals as $name => $value) {
                $score += (float) ($weights[$name] ?? 0) * $value;
            }

            $reasons = $this->reasons($row, $preset, $params, $dropThreshold);

            $scored[] = $row + [
                'score' => (int) round($score * 100),
                'reasons' => $reasons,
                'explanation' => implode('; ', $reasons),
            ];
        }

        usort($scored, function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            // При равной оценке вперёд идёт тот, у кого больше денег на кону.
            return ($b['lag'] ?? 0) <=> ($a['lag'] ?? 0);
        });

        return $scored;
    }

    /**
     * Почему клиент в списке — словами, а не кодами сигналов.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function reasons(array $row, OpportunityPreset $preset, array $params, int $dropThreshold): array
    {
        $reasons = [];

        if ($preset === OpportunityPreset::NOT_BUYING && ! empty($params['label'])) {
            $reasons[] = 'не берёт: '.$params['label'];
        }

        if (($row['lag'] ?? 0) > 0) {
            $reasons[] = $row['percent'] !== null
                ? 'недобор плана '.$this->money($row['lag']).' (выполнено '.$row['percent'].'%)'
                : 'недобор плана '.$this->money($row['lag']);
        }

        if ($row['last_purchase_at'] === null) {
            $reasons[] = $row['registered_days'] !== null
                ? 'ни одной отгрузки за всё время, в базе '.$row['registered_days'].' дн.'
                : 'ни одной отгрузки за всё время';
        } elseif (($row['overdue_days'] ?? 0) > 0) {
            $reasons[] = $row['has_own_cycle']
                ? 'не покупает '.$row['days_since'].' дн. при обычном цикле '.$row['cycle_days']
                : 'не покупает '.$row['days_since'].' дн. (цикл в профиле не задан, считаем от '.$row['cycle_days'].')';
        }

        if (($row['drop_percent'] ?? 0) >= $dropThreshold) {
            $reasons[] = 'просел на '.$row['drop_percent'].'% против прошлого месяца';
        }

        if (($row['avg_check'] ?? 0) > 0) {
            $reasons[] = 'средний чек '.$this->money($row['avg_check']);
        }

        if ($row['abc'] === 'A') {
            $reasons[] = 'класс A по обороту за год';
        }

        return $reasons;
    }

    /**
     * Класс ABC по обороту за 12 месяцев: A — первые 80 % выручки, B — до 95 %.
     *
     * Считается здесь, а не в {@see ShipmentAnalyticsService::abcXyz()}: тот метод
     * знает измерения бренд/категория/товар, но не партнёра, и расширять общий
     * сервис ради одного экрана было бы дороже, чем накопить долю по уже
     * посчитанному агрегату. Своего запроса к отгрузкам эти пять строк не делают.
     *
     * @param  array<int, array{amount: float, shipments: int}>  $year
     * @return array<int, string>
     */
    private function abcClasses(array $year): array
    {
        $amounts = array_filter(
            array_map(fn (array $row): float => $row['amount'], $year),
            fn (float $amount): bool => $amount > 0,
        );

        $total = array_sum($amounts);

        if ($total <= 0) {
            return [];
        }

        arsort($amounts);

        $classes = [];
        $cumulative = 0.0;

        foreach ($amounts as $id => $amount) {
            // Класс определяет доля, накопленная ДО этого клиента: иначе
            // единственный крупный клиент, дающий 99 % выручки, сам пробивал бы
            // порог и получал класс C. Первый в списке — всегда A.
            $share = $cumulative / $total;
            $classes[$id] = $share < 0.8 ? 'A' : ($share < 0.95 ? 'B' : 'C');
            $cumulative += $amount;
        }

        return $classes;
    }

    /**
     * Оборот и число отгрузок по клиентам за период — из общего движка аналитики.
     *
     * @return array<int, array{amount: float, shipments: int}>
     */
    private function partnerAmounts(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit): array
    {
        if ($ctx->isEmpty()) {
            return [];
        }

        $result = [];

        // Лимит по числу клиентов: byPartner по умолчанию отдаёт топ-50, и хвост
        // длинного списка молча получил бы нулевой оборот.
        foreach ($this->analytics->byPartner($ctx, $filters, max(1, $limit)) as $row) {
            if ($row['partner_id'] === null) {
                continue;
            }

            $result[(int) $row['partner_id']] = [
                'amount' => (float) $row['amount'],
                'shipments' => (int) $row['shipments_count'],
            ];
        }

        return $result;
    }

    /**
     * Имя, контакты, менеджер и цикл закупок из профиля.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function clientMeta(array $ids): array
    {
        $rows = DB::table('users')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'users.personal_manager_id')
            ->leftJoin('crm_client_profiles as cp', 'cp.user_id', '=', 'users.id')
            ->whereIn('users.id', $ids)
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.created_at',
                'pm.id as manager_id',
                'pm.name as manager_name',
                'cp.order_cycle_days',
            )
            ->get();

        $meta = [];

        foreach ($rows as $row) {
            $meta[(int) $row->id] = [
                'name' => (string) ($row->name ?: ($row->email ?: 'Клиент #'.$row->id)),
                'email' => $row->email,
                'phone' => $row->phone,
                'manager_id' => $row->manager_id ? (int) $row->manager_id : null,
                'manager' => $row->manager_name ?: null,
                'order_cycle_days' => $row->order_cycle_days !== null ? (int) $row->order_cycle_days : null,
                'created_at' => $row->created_at,
            ];
        }

        return $meta;
    }

    private function filters(CarbonImmutable $from, CarbonImmutable $to): AnalyticsFilters
    {
        return new AnalyticsFilters(
            dateFrom: $from->startOfDay(),
            dateTo: $to->endOfDay(),
        );
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' ₽';
    }
}
