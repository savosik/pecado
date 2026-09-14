<?php

namespace App\Services\Crm;

use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\GapAnalysisService;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Support\Crm\LastVisit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Сигналы по партнёрам скоупа: факт месяца, падение к прошлому, оборот за год,
 * ABC-класс, дата последней покупки, просрочка цикла.
 *
 * Разделы «Возможности» и «Грядки» сняты 15.09.2026 (стояли на планах на
 * партнёра); сигналы остались — их читают экраны «Мотивация → Мои клиенты».
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
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly GapAnalysisService $gap,
        private readonly ClientPlanFactService $planFact,
    ) {}

    /**
     * Сигналы по каждому партнёру скоупа, без отбора и ранжирования.
     *
     * Открыто для витрин, которым нужен не список «кому звонить», а состояние
     * всей базы разом — «грядки» (crm-08) рисуют по нему плитки. Отдаётся тот же
     * кэшированный набор, что питает пресеты: вторая копия расчёта означала бы,
     * что партнёр спит на одном экране и не спит на другом.
     *
     * @return array<int, array<string, mixed>> ключ — идентификатор партнёра
     */
    public function signals(CarbonInterface $month, PlanScope $scope): array
    {
        return $this->dataset($month, $scope);
    }

    /**
     * Сигналы по каждому партнёру скоупа. Кэшируется по месяцу и составу скоупа:
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
                continue;   // партнёр исчез между выборкой скоупа и расчётом
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
            // Класс определяет доля, накопленная ДО этого партнёра: иначе
            // единственный крупный партнёр, дающий 99 % выручки, сам пробивал бы
            // порог и получал класс C. Первый в списке — всегда A.
            $share = $cumulative / $total;
            $classes[$id] = $share < 0.8 ? 'A' : ($share < 0.95 ? 'B' : 'C');
            $cumulative += $amount;
        }

        return $classes;
    }

    /**
     * Оборот и число отгрузок по партнёрам за период — из общего движка аналитики.
     *
     * @return array<int, array{amount: float, shipments: int}>
     */
    private function partnerAmounts(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit): array
    {
        if ($ctx->isEmpty()) {
            return [];
        }

        $result = [];

        // Лимит по числу партнёров: byPartner по умолчанию отдаёт топ-50, и хвост
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
                // Подпись партнёра в CRM — рабочее наименование из 1С; личное имя
                // подставляем, только когда карточки в 1С нет.
                DB::raw("COALESCE(NULLIF(users.erp_name, ''), users.name) as name"),
                'users.email',
                'users.phone',
                'users.created_at',
                'users.last_seen_at',
                'pm.id as manager_id',
                'pm.name as manager_name',
                'cp.order_cycle_days',
            )
            ->get();

        $meta = [];

        foreach ($rows as $row) {
            $meta[(int) $row->id] = [
                'name' => (string) ($row->name ?: ($row->email ?: 'Партнёр #'.$row->id)),
                'email' => $row->email,
                'phone' => $row->phone,
                'manager_id' => $row->manager_id ? (int) $row->manager_id : null,
                'manager' => $row->manager_name ?: null,
                'order_cycle_days' => $row->order_cycle_days !== null ? (int) $row->order_cycle_days : null,
                'created_at' => $row->created_at,
                // Строка из-под DB::raw-выборки, а не модель: приводим сами.
                'last_visit' => LastVisit::payload(
                    $row->last_seen_at === null ? null : CarbonImmutable::parse($row->last_seen_at),
                ),
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
}
