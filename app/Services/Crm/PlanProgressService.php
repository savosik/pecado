<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Выполнение месячных планов: сводка, прогноз, burndown и разрез по менеджерам.
 *
 * Факт продаж берётся **только** из {@see ShipmentAnalyticsService} — того же движка,
 * что считает /crm/analytics. Свой SUM по отгрузкам здесь был бы вторым расчётом,
 * и цифры двух экранов рано или поздно разошлись бы на мягком удалении, валютах
 * или фильтрах. Расхождение выполнения плана с отчётом продаж — баг по определению.
 *
 * Бизнес-дата — `shipments.erp_created_at` (дата документа 1С), а не `created_at`:
 * историю 1С на проде затянули одним импортом в мае 2026, и по дате создания записи
 * вся база выглядит проданной за один день.
 *
 * Ввод и хранение планов живут в {@see SalesPlanService}.
 */
class PlanProgressService
{
    /**
     * Текущий месяц — пять минут: отгрузки приезжают из 1С пачками, а дашборд
     * открывают часто, и каждый заход дёргал бы несколько тяжёлых агрегатов.
     */
    private const OPEN_MONTH_TTL = 300;

    /**
     * Закрытый месяц пересчитывать незачем — сутки.
     */
    private const CLOSED_MONTH_TTL = 86400;

    /**
     * Порог, за которым темп перестаёт считаться нормальным.
     *
     * ±5% — чтобы «на день опережения» не подсвечивалось тревожным цветом:
     * колебание в пределах одной средней отгрузки темпом не является.
     */
    private const PACE_TOLERANCE = 0.05;

    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly ClientPlanFactService $clientPlanFact,
    ) {}

    /**
     * Сводка выполнения: план, факт, остаток, прогноз при текущем темпе и темп.
     *
     * @return array{
     *   plan: float|null, fact: float, percent: int|null, remaining: float|null,
     *   forecast: float|null, needed_per_day: float|null,
     *   days_passed: int, days_total: int, days_left: int,
     *   pace: 'ahead'|'on_track'|'behind'|null
     * }
     */
    public function progress(CarbonInterface $month, PlanScope $scope): array
    {
        $timeline = $this->timeline($month);
        $plan = $this->planAmount($month, $scope);
        $fact = $this->fact($month, $scope);

        $remaining = $plan !== null ? max(0.0, $plan - $fact) : null;
        $daysPassed = $timeline['days_passed'];
        $daysLeft = $timeline['days_left'];

        return [
            'plan' => $plan,
            'fact' => round($fact, 2),
            // Процент только при положительном плане: ноль означает «в этом месяце
            // не продаём», и делить на него нечего.
            'percent' => $plan !== null && $plan > 0 ? (int) round($fact / $plan * 100) : null,
            'remaining' => $remaining !== null ? round($remaining, 2) : null,
            // Линейная экстраполяция, и ничего больше. Подписывается «при текущем
            // темпе» — сезонности в этой цифре нет.
            'forecast' => $daysPassed > 0 ? round($fact / $daysPassed * $timeline['days_total'], 2) : null,
            'needed_per_day' => $remaining !== null && $daysLeft > 0 ? round($remaining / $daysLeft, 2) : null,
            'days_passed' => $daysPassed,
            'days_total' => $timeline['days_total'],
            'days_left' => $daysLeft,
            'pace' => $this->pace($plan, $fact, $daysPassed, $timeline['days_total']),
        ];
    }

    /**
     * Точки burndown: сколько осталось добрать против идеальной линии.
     *
     * Идеальная линия — равномерное списание плана по дням месяца. Фактическая —
     * `план − накопленный факт` на конец дня. Будущие дни не рисуются: остаток,
     * протянутый горизонталью до конца месяца, читается как «работа встала».
     *
     * @return list<array{date: string, ideal_remaining: float|null, actual_remaining: float|null, fact_cumulative: float}>
     */
    public function burndown(CarbonInterface $month, PlanScope $scope): array
    {
        $timeline = $this->timeline($month);
        $plan = $this->planAmount($month, $scope);
        $daily = $this->dailyFact($month, $scope);

        $points = [];
        $cumulative = 0.0;
        $dayNumber = 0;

        foreach ($daily as $date => $amount) {
            $dayNumber++;
            $cumulative += $amount;

            $points[] = [
                'date' => $date,
                'ideal_remaining' => $plan !== null
                    ? round(max(0.0, $plan * (1 - $dayNumber / $timeline['days_total'])), 2)
                    : null,
                'actual_remaining' => $plan !== null ? round($plan - $cumulative, 2) : null,
                'fact_cumulative' => round($cumulative, 2),
            ];
        }

        return $points;
    }

    /**
     * Разрез по менеджерам: план, факт, процент, прогноз и число активных партнёров.
     *
     * Гейт — `crm-clients-all.view`: чужая цифра выручки не дело соседнего менеджера.
     * Без права метод отдаёт пустой список, а не бросает исключение: вызывающий
     * (контроллер, экспорт, будущий агентский API) просто не показывает блок.
     *
     * @return list<array<string, mixed>>
     */
    public function byManager(CarbonInterface $month, User $actor): array
    {
        if (! $actor->can('crm-clients-all.view')) {
            return [];
        }

        $clientIds = $this->visibleClientIds($actor);
        $timeline = $this->timeline($month);

        $facts = $this->managerFacts($month, $clientIds);
        $plans = CrmSalesPlan::query()
            ->forPeriod($month)
            ->where('target_type', PlanTarget::MANAGER->value)
            ->pluck('amount', 'target_id');

        // Скрытые карточки не отфильтровываем запросом: если за менеджером
        // числятся отгрузки месяца, его выручка обязана остаться в разрезе,
        // иначе сумма строк не сойдётся с итогом отдела. Пустые строки уберёт
        // общий фильтр ниже — он же убирает и весь мусор справочника.
        $managers = PersonalManager::query()
            ->select('id', 'name', 'is_active')
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($managers as $manager) {
            $id = (int) $manager->getKey();
            $plan = $plans->has($id) ? (float) $plans->get($id) : null;
            $fact = (float) ($facts[$id]['amount'] ?? 0.0);

            $rows[] = [
                'manager_id' => $id,
                'name' => (string) $manager->name,
                'is_active' => (bool) $manager->is_active,
                'plan' => $plan,
                'fact' => round($fact, 2),
                'percent' => $plan !== null && $plan > 0 ? (int) round($fact / $plan * 100) : null,
                'forecast' => $timeline['days_passed'] > 0
                    ? round($fact / $timeline['days_passed'] * $timeline['days_total'], 2)
                    : null,
                'clients_count' => (int) ($facts[$id]['clients_count'] ?? 0),
                'pace' => $this->pace($plan, $fact, $timeline['days_passed'], $timeline['days_total']),
            ];
        }

        // Менеджеры без плана и без отгрузок в строках не нужны: карточка в
        // справочнике может остаться от уволившегося, а пустая строка в отчёте
        // выглядит как «человек ничего не продал».
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['plan'] !== null || $row['fact'] > 0,
        ));
    }

    /**
     * Партнёры скоупа: план, факт, процент и отставание — для таблицы «кого догонять».
     *
     * Сортировка по отставанию (`план − факт`), поэтому нужен факт по всем партнёрам
     * сразу, а не по странице: страница из пятнадцати строк для отбора отстающих
     * бесполезна. Расчёт переиспользует {@see ClientPlanFactService} — тот же кэш,
     * что и у колонки «План / факт» в списке партнёров.
     *
     * @return list<array<string, mixed>>
     */
    public function clients(CarbonInterface $month, PlanScope $scope, User $actor, int $limit = 200): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $query = User::query()
            ->visibleInCrm($actor)
            ->whereIn('users.id', $scope->clientIds);

        $planFact = $this->clientPlanFact->forScope($query->clone(), $month);

        if ($planFact === []) {
            return [];
        }

        $names = $query
            ->select('users.id', 'users.email', DB::raw("COALESCE(NULLIF(users.erp_name, ''), users.name) as name"))
            ->pluck('name', 'id');

        $rows = [];

        foreach ($planFact as $id => $cell) {
            $plan = $cell['plan'];
            $fact = (float) $cell['fact'];

            // Партнёры без плана и без отгрузок — это просто остальная база
            // менеджера, в отчёте о выполнении им делать нечего.
            if ($plan === null && $fact <= 0) {
                continue;
            }

            $rows[] = [
                'id' => (int) $id,
                'name' => (string) ($names[$id] ?? ('Партнёр #'.$id)),
                'plan' => $plan,
                'fact' => round($fact, 2),
                'percent' => $cell['percent'],
                // Отставание считаем только при плане: без него «догонять» нечего,
                // и такие строки уходят вниз списка.
                'lag' => $plan !== null ? round(max(0.0, $plan - $fact), 2) : null,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if (($a['lag'] === null) !== ($b['lag'] === null)) {
                return $a['lag'] === null ? 1 : -1;
            }

            return $a['lag'] === null
                ? $b['fact'] <=> $a['fact']
                : $b['lag'] <=> $a['lag'];
        });

        return array_slice($rows, 0, $limit);
    }

    /**
     * Сверка «сумма планов уровнем ниже против плана скоупа».
     *
     * Это подсказка, а не ошибка: руководитель может поставить отделу цифру больше
     * суммы планов менеджеров (запас на новых партнёров), а менеджер — расписать
     * свою цифру по партнёрам не полностью. Показываем расхождение с суммой и знаком.
     *
     * @return array{label: string, plan: float|null, sum: float, diff: float}|null
     */
    public function distribution(CarbonInterface $month, PlanScope $scope): ?array
    {
        if ($scope->target === PlanTarget::CLIENT) {
            return null;
        }

        $plan = $this->planAmount($month, $scope);

        if ($scope->target === PlanTarget::DEPARTMENT) {
            $sum = (float) CrmSalesPlan::query()
                ->forPeriod($month)
                ->where('target_type', PlanTarget::MANAGER->value)
                ->sum('amount');
            $label = 'Сумма планов менеджеров';
        } else {
            $sum = $scope->isEmpty() ? 0.0 : (float) CrmSalesPlan::query()
                ->forPeriod($month)
                ->where('target_type', PlanTarget::CLIENT->value)
                ->whereIn('target_id', $scope->clientIds)
                ->sum('amount');
            $label = 'Сумма планов партнёров';
        }

        return [
            'label' => $label,
            'plan' => $plan,
            'sum' => round($sum, 2),
            'diff' => round($sum - (float) ($plan ?? 0), 2),
        ];
    }

    /**
     * Дни месяца: сколько всего, сколько прошло, сколько осталось.
     *
     * @return array{days_total: int, days_passed: int, days_left: int}
     */
    public function timeline(CarbonInterface $month): array
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();
        $total = (int) $start->daysInMonth;
        $today = CarbonImmutable::now();

        if ($today->lessThan($start)) {
            $passed = 0;                       // месяц ещё не начался
        } elseif ($today->greaterThan($start->endOfMonth())) {
            $passed = $total;                  // месяц закрыт
        } else {
            $passed = (int) $today->day;
        }

        return [
            'days_total' => $total,
            'days_passed' => $passed,
            'days_left' => max(0, $total - $passed),
        ];
    }

    /**
     * Сумма плана скоупа. `null` — плана нет (не то же самое, что план ноль).
     */
    public function planAmount(CarbonInterface $month, PlanScope $scope): ?float
    {
        $plan = CrmSalesPlan::query()
            ->forPeriod($month)
            ->where('target_type', $scope->target->value)
            ->where('target_id', CrmSalesPlan::targetKey($scope->target, $scope->targetId))
            ->first();

        return $plan?->amountValue();
    }

    /**
     * Факт скоупа за месяц — в рублях, по бизнес-дате 1С.
     */
    private function fact(CarbonInterface $month, PlanScope $scope): float
    {
        if ($scope->isEmpty()) {
            return 0.0;
        }

        return $this->cached('total', $month, $scope, function () use ($month, $scope): float {
            $metrics = $this->analytics->metrics(
                $this->context($scope),
                $this->monthFilters($month),
            );

            return (float) $metrics['total_amount'];
        });
    }

    /**
     * Факт по дням месяца до сегодняшнего включительно: 'Y-m-d' => сумма.
     *
     * Ряд с каркасом пустых дней даёт {@see ShipmentAnalyticsService::timeSeries()} —
     * вручную дни не перебираем.
     *
     * @return array<string, float>
     */
    private function dailyFact(CarbonInterface $month, PlanScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        return $this->cached('daily', $month, $scope, function () use ($scope, $month): array {
            $series = $this->analytics->timeSeries(
                $this->context($scope),
                $this->monthFilters($month, untilToday: true),
            );

            $result = [];
            foreach ($series['points'] as $point) {
                $result[(string) $point['period']] = (float) $point['amount'];
            }

            return $result;
        });
    }

    /**
     * Факт и число активных партнёров в разрезе менеджеров.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array{amount: float, clients_count: int}>
     */
    private function managerFacts(CarbonInterface $month, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $scope = PlanScope::department($clientIds);

        return $this->cached('by-manager', $month, $scope, function () use ($scope, $month): array {
            $rows = $this->analytics->byManager(
                $this->context($scope),
                $this->monthFilters($month),
            );

            $result = [];
            foreach ($rows as $row) {
                if ($row['manager_id'] === null) {
                    continue;   // «Без менеджера» — в CRM таких партнёров нет по определению
                }
                $result[(int) $row['manager_id']] = [
                    'amount' => (float) $row['amount'],
                    'clients_count' => (int) $row['clients_count'],
                ];
            }

            return $result;
        });
    }

    /**
     * Контекст расчёта: партнёры скоупа, бизнес-дата 1С, рубли.
     */
    private function context(PlanScope $scope): AnalyticsContext
    {
        return AnalyticsContext::forScope($scope->clientIds, AnalyticsContext::DATE_ERP, null);
    }

    /**
     * Границы месяца. `untilToday` обрезает окно сегодняшним днём — для burndown,
     * где будущие дни не рисуются.
     */
    private function monthFilters(CarbonInterface $month, bool $untilToday = false): AnalyticsFilters
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();
        $end = $start->endOfMonth();

        if ($untilToday) {
            $today = CarbonImmutable::now()->endOfDay();
            $end = $today->lessThan($end) ? $today : $end;
        }

        return new AnalyticsFilters(
            dateFrom: $start->startOfDay(),
            dateTo: $end,
        );
    }

    /**
     * Темп: успеваем ли к концу месяца при равномерном плане.
     *
     * @return 'ahead'|'on_track'|'behind'|null
     */
    private function pace(?float $plan, float $fact, int $daysPassed, int $daysTotal): ?string
    {
        if ($plan === null || $plan <= 0 || $daysPassed === 0) {
            return null;
        }

        $expected = $plan * $daysPassed / $daysTotal;

        if ($fact >= $expected * (1 + self::PACE_TOLERANCE)) {
            return 'ahead';
        }

        if ($fact <= $expected * (1 - self::PACE_TOLERANCE)) {
            return 'behind';
        }

        return 'on_track';
    }

    /**
     * Партнёры, видимые актору в CRM.
     *
     * @return list<int>
     */
    private function visibleClientIds(User $actor): array
    {
        /** @var list<int> $ids */
        $ids = User::query()->visibleInCrm($actor)->pluck('users.id')->map('intval')->all();

        return $ids;
    }

    /**
     * Кэш агрегата. Закрытый месяц не меняется — держим сутки, текущий — пять минут.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback
     * @return TValue
     */
    private function cached(string $bucket, CarbonInterface $month, PlanScope $scope, \Closure $callback): mixed
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $isClosed = CarbonImmutable::now()->greaterThan($period->endOfMonth());

        $key = 'crm:plan-progress:'.$bucket.':'.$period->format('Y-m').':'.$scope->cacheKey();

        return Cache::remember(
            $key,
            $isClosed ? self::CLOSED_MONTH_TTL : self::OPEN_MONTH_TTL,
            $callback,
        );
    }
}
