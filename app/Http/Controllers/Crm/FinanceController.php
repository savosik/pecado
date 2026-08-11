<?php

namespace App\Http\Controllers\Crm;

use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Finance\FinanceFilters;
use App\Services\Crm\Finance\PaymentForecast;
use App\Services\SimpleXlsxExporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Финансовый раздел CRM: сколько денег придёт, когда и от кого.
 *
 * Изоляция данных — скоуп партнёров User::visibleInCrm(): менеджер видит деньги
 * только своих партнёров, весь отдел и разрез по менеджерам — у РОПа
 * (crm-clients-all.view). Тот же приём, что в журналах документов и аналитике.
 *
 * Расчёт живёт в реализации PaymentForecast: пульт, таблицы, календарь и выгрузка
 * обязаны показывать одно и то же число.
 */
class FinanceController extends CrmController
{
    /** Потолок строк на страницу таблиц раздела. */
    private const PER_PAGE = 50;

    /** Сколько строк просрочки показывать на пульте, не уводя менеджера в отдельную страницу. */
    private const DASHBOARD_ROWS = 15;

    public function __construct(
        private readonly PaymentForecast $forecast,
    ) {}

    /**
     * Пульт платежей. GET /crm/finance
     */
    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Crm/Pages/Finance/Index', $this->dashboardPayload($request));
    }

    /**
     * JSON для XHR-обновления пульта при смене фильтров. GET /crm/finance/data
     */
    public function data(Request $request): JsonResponse
    {
        return response()->json($this->dashboardPayload($request));
    }

    /**
     * План поступлений — построчно. GET /crm/finance/plan
     */
    public function plan(Request $request): InertiaResponse
    {
        return Inertia::render('Crm/Pages/Finance/Plan', $this->tablePayload($request, overdueOnly: false));
    }

    /**
     * Просрочка — те же строки, но с прошедшей плановой датой. GET /crm/finance/overdue
     */
    public function overdue(Request $request): InertiaResponse
    {
        return Inertia::render('Crm/Pages/Finance/Overdue', $this->tablePayload($request, overdueOnly: true));
    }

    /**
     * Балансы партнёров из 1С. GET /crm/finance/balances
     */
    public function balances(Request $request): InertiaResponse
    {
        $filters = FinanceFilters::fromRequest($request);
        $clients = $this->visibleClients($request, $filters);

        return Inertia::render('Crm/Pages/Finance/Balances', [
            'balances' => $this->forecast->balances($clients),
            // Сводка нужна ради одной строки сверки в шапке: просрочка по данным 1С
            // против нашего расчёта по графику. Построчно её больше не показываем —
            // это разные разрезы (1С считает по контрагенту, график по документу),
            // и рядом в таблице они читались как ошибка.
            'summary' => $this->forecast->summary($clients, $filters),
            ...$this->sharedOptions($request, $filters),
        ]);
    }

    /**
     * Выгрузка «сколько денег и когда»: сводка + детализация + балансы.
     * GET /crm/finance/export
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $filters = FinanceFilters::fromRequest($request);
        $clients = $this->visibleClients($request, $filters);
        $today = CarbonImmutable::today();

        $plan = $this->forecast->dailyPlan($clients, $filters);
        $facts = $this->forecast->factsByDay($clients, $filters->dateFrom->toDateString(), $filters->dateTo->toDateString());
        $aging = $this->forecast->aging($clients, $filters);
        $summary = $this->forecast->summary($clients, $filters);

        return $exporter->streamSheets('crm-finance-'.$today->format('Y-m-d'), [
            [
                'title' => 'Сводка',
                'headers' => ['Показатель', 'Значение, ₽', 'Комментарий'],
                'rows' => $this->summarySheet($filters, $plan, $facts, $aging, $summary),
            ],
            [
                'title' => 'Детализация',
                'headers' => [
                    'Плановая дата', 'Дней до срока', 'Дней просрочки', 'Партнёр', 'Менеджер',
                    'Организация', 'Реализация', 'Дата реализации', 'Счёт-фактура',
                    'Сумма документа', 'Сумма платежа', 'Оплачено', 'Остаток, ₽',
                    'Валюта', 'Остаток в валюте', 'Этап оплаты',
                ],
                'rows' => $this->detailSheet($clients, $filters, $today),
            ],
            [
                'title' => 'Балансы',
                'headers' => [
                    'Партнёр', 'Менеджер', 'Контрагент', 'ИНН',
                    'Сальдо, ₽', 'Просрочено, ₽', 'Данные 1С от',
                ],
                // Построчно по контрагентам: 1С ведёт расчёты именно по ним, и
                // свёрнутый до партнёра лист нельзя было бы сверить с учётной системой.
                'rows' => $this->balancesSheet($clients),
            ],
        ]);
    }

    /**
     * Лист «Балансы»: строка на контрагента, имя партнёра дублируется в каждой —
     * так лист фильтруется и сводится в самом Excel.
     *
     * @param  Builder<User>  $clients
     * @return list<array<int, scalar|null>>
     */
    private function balancesSheet(Builder $clients): array
    {
        $rows = [];

        foreach ($this->forecast->balances($clients) as $client) {
            foreach ($client['contractors'] as $contractor) {
                $rows[] = [
                    $client['client']['name'],
                    $client['manager_name'],
                    $contractor['company_name'],
                    $contractor['tax_id'],
                    $contractor['current_balance'],
                    $contractor['overdue_debt'],
                    $contractor['erp_updated_at'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Данные пульта. Общие для Inertia-страницы и XHR-обновления.
     *
     * @return array<string, mixed>
     */
    private function dashboardPayload(Request $request): array
    {
        $filters = FinanceFilters::fromRequest($request);
        $clients = $this->visibleClients($request, $filters);
        $today = CarbonImmutable::today();

        $plan = $this->forecast->dailyPlan($clients, $filters);
        $facts = $this->forecast->factsByDay($clients, $filters->dateFrom->toDateString(), $filters->dateTo->toDateString());

        $overdueRows = $this->forecast->applyDefaultOrder(
            $this->forecast->overdueOnly($this->forecast->plannedQuery($clients, $filters), $today)
        )->limit(self::DASHBOARD_ROWS)->get();

        $upcomingRows = $this->forecast->applyDefaultOrder(
            $this->forecast->dueBetween(
                $this->forecast->plannedQuery($clients, $filters),
                $today->toDateString(),
                $filters->dateTo->toDateString(),
            )
        )->limit(self::DASHBOARD_ROWS)->get();

        return [
            'summary' => $this->forecast->summary($clients, $filters),
            'buckets' => $this->forecast->buckets($plan, $facts, $filters),
            'aging' => $this->forecast->aging($clients, $filters),
            'topDebtors' => $this->forecast->topDebtors($clients, $filters),
            'overdueRows' => $overdueRows->map(fn (object $row): array => $this->forecast->row($row, $today))->all(),
            'upcomingRows' => $upcomingRows->map(fn (object $row): array => $this->forecast->row($row, $today))->all(),
            'noScheduleCount' => $filters->includeNoSchedule ? $this->forecast->noScheduleCount($clients, $filters) : 0,
            ...$this->sharedOptions($request, $filters),
        ];
    }

    /**
     * Данные страниц «План поступлений» и «Просрочка».
     *
     * Страница просрочки — тот же набор строк с принудительным only_overdue:
     * два независимых запроса разошлись бы в трактовке «сегодня».
     *
     * @return array<string, mixed>
     */
    private function tablePayload(Request $request, bool $overdueOnly): array
    {
        $filters = FinanceFilters::fromRequest($request);
        $clients = $this->visibleClients($request, $filters);
        $today = CarbonImmutable::today();

        $query = $this->forecast->plannedQuery($clients, $filters);

        if ($overdueOnly) {
            $this->forecast->overdueOnly($query, $today);
        } else {
            $this->forecast->dueBetween(
                $query,
                $filters->dateFrom->toDateString(),
                $filters->dateTo->toDateString(),
            );
        }

        /** @var LengthAwarePaginator<int, object> $rows */
        $rows = $this->forecast->applyDefaultOrder($query)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->through(fn (object $row): array => $this->forecast->row($row, $today));

        return [
            'rows' => $rows,
            'summary' => $this->forecast->summary($clients, $filters),
            'aging' => $overdueOnly ? $this->forecast->aging($clients, $filters) : null,
            'noSchedule' => $filters->includeNoSchedule && ! $overdueOnly
                ? $this->noSchedulePayload($clients, $filters, $today)
                : null,
            ...$this->sharedOptions($request, $filters),
        ];
    }

    /**
     * Долг реализаций без графика от 1С — отдельным блоком под таблицей.
     *
     * Не подмешиваем его в основной список: у этих строк нет плановой даты, и
     * в отсортированной по дате таблице они висели бы непонятным хвостом.
     *
     * @param  Builder<User>  $clients
     * @return array{count: int, amount: float, rows: list<array<string, mixed>>}
     */
    private function noSchedulePayload(Builder $clients, FinanceFilters $filters, CarbonImmutable $today): array
    {
        $rows = $this->forecast->noScheduleQuery($clients, $filters)
            ->orderByDesc('s.date')
            ->limit(self::DASHBOARD_ROWS)
            ->get()
            ->map(fn (object $row): array => $this->forecast->row($row, $today));

        return [
            'count' => $this->forecast->noScheduleCount($clients, $filters),
            'amount' => $this->forecast->noScheduleTotal($clients, $filters),
            'rows' => $rows->all(),
        ];
    }

    /**
     * Лист «Сводка»: сначала итоги, затем разбивки по времени и давности долга.
     *
     * @param  \Illuminate\Support\Collection<string, float>  $plan
     * @param  \Illuminate\Support\Collection<string, array{amount: float, count: int}>  $facts
     * @param  array<string, mixed>  $aging
     * @param  array<string, mixed>  $summary
     * @return list<array<int, scalar|null>>
     */
    private function summarySheet(
        FinanceFilters $filters,
        \Illuminate\Support\Collection $plan,
        \Illuminate\Support\Collection $facts,
        array $aging,
        array $summary,
    ): array {
        $rows = [
            ['Период отчёта', null, $filters->rangeLabel()],
            ['Выгрузка сформирована', null, CarbonImmutable::now()->format('d.m.Y H:i')],
            [null, null, null],
            ['Ожидается за период', $summary['expected_period'], 'По графику оплаты реализаций'],
            ['Ожидается в ближайшие 7 дней', $summary['expected_7'], null],
            ['Ожидается в ближайшие 14 дней', $summary['expected_14'], null],
            ['Ожидается в ближайшие 30 дней', $summary['expected_30'], null],
            ['Ожидается в текущем месяце', $summary['expected_month'], null],
            ['Просрочено', $summary['overdue_amount'], 'Строк: '.$summary['overdue_count'].', партнёров: '.$summary['overdue_clients']],
            ['Долг без графика оплаты', $summary['no_schedule_amount'], '1С не прислала «Правила оплаты» по этим реализациям'],
            ['Сальдо партнёров по 1С', $summary['debt_total'], 'Отрицательное значение — долг партнёра'],
            ['Просрочка по данным 1С', $summary['erp_overdue_total'], 'Для сверки с расчётом по графику'],
            ['Авансы (нераспределённые платежи)', $summary['advances'], 'Деньги пришли, но не разнесены на документы'],
            [null, null, null],
            ['Ожидается по периодам', null, null],
        ];

        foreach ($this->forecast->buckets($plan, $facts, $filters) as $bucket) {
            $rows[] = [$bucket['label'], $bucket['plan'], 'Факт за период: '.$bucket['fact']];
        }

        $rows[] = [null, null, null];
        $rows[] = ['Просрочка по срокам', null, null];

        foreach ($aging['buckets'] as $bucket) {
            $rows[] = [$bucket['label'], $bucket['amount'], 'Строк: '.$bucket['count']];
        }

        return $rows;
    }

    /**
     * Лист «Детализация»: строки графика, затем долг без графика.
     *
     * Читается сверху вниз как «вот когда и сколько придёт», поэтому сортировка —
     * по плановой дате. Выборка идёт чанками: в лист складываются уже скаляры,
     * а не объекты, иначе выгрузка на тысячах строк съест память.
     *
     * @param  Builder<User>  $clients
     * @return list<array<int, scalar|null>>
     */
    private function detailSheet(Builder $clients, FinanceFilters $filters, CarbonImmutable $today): array
    {
        $rows = [];

        $collect = function (object $raw) use (&$rows, $today): void {
            $row = $this->forecast->row($raw, $today);

            $rows[] = [
                $row['due_date_label'],
                $row['days_left'],
                $row['days_overdue'] ?: null,
                $row['client']['name'],
                $row['manager_name'],
                $row['organization_name'],
                $row['shipment']['number'],
                $row['shipment']['date'],
                $row['shipment']['invoice_number'],
                $row['shipment']['total'],
                $row['amount'],
                $row['paid_amount'],
                $row['unpaid_rub'],
                $row['currency_code'],
                $row['unpaid_amount'],
                $row['stage_name'],
            ];
        };

        $planned = $this->forecast->plannedQuery($clients, $filters);

        if (! $filters->onlyOverdue) {
            $planned->whereBetween('sch.due_date', [
                $filters->dateFrom->toDateString(),
                $filters->dateTo->toDateString(),
            ]);
        }

        $this->forecast->applyDefaultOrder($planned)->chunk(500, function ($chunk) use ($collect): void {
            $chunk->each($collect);
        });

        if ($filters->includeNoSchedule && ! $filters->onlyOverdue) {
            $this->forecast->noScheduleQuery($clients, $filters)
                ->orderByDesc('s.date')
                ->orderBy('s.id')
                ->chunk(500, function ($chunk) use ($collect): void {
                    $chunk->each($collect);
                });
        }

        return $rows;
    }

    /**
     * Опции и текущее состояние фильтров — общие для всех страниц раздела.
     *
     * @return array<string, mixed>
     */
    private function sharedOptions(Request $request, FinanceFilters $filters): array
    {
        $seesAll = $this->seesAllClients($request);

        return [
            'filters' => [
                ...$filters->toArray(),
                // Разрез по менеджерам менеджеру не показываем и не возвращаем:
                // иначе в интерфейсе появился бы фильтр, который ничего не делает.
                'manager_ids' => $seesAll ? $filters->managerIds : [],
            ],
            'managers' => $seesAll ? $this->managerOptions() : [],
            'organizations' => $this->organizationOptions(),
            'seesAll' => $seesAll,
        ];
    }

    /**
     * Скоуп партнёров раздела.
     *
     * @return Builder<User>
     */
    private function visibleClients(Request $request, FinanceFilters $filters): Builder
    {
        $actor = $this->crmActor($request);
        $query = User::query()->visibleInCrm($actor)->select('users.id');

        // Менеджера подставляет только РОП: у рядового менеджера скоуп и так сведён
        // к своим партнёрам, а чужой id в запросе не должен ничего давать.
        if ($this->seesAllClients($request) && $filters->managerIds !== []) {
            $query->whereIn('users.personal_manager_id', $filters->managerIds);
        }

        return $query;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function managerOptions(): array
    {
        return PersonalManager::query()
            ->active()
            ->whereHas('users')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PersonalManager $manager): array => [
                'id' => (int) $manager->getKey(),
                'name' => (string) $manager->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function organizationOptions(): array
    {
        if (! config('erp.organizations.enabled')) {
            return [];
        }

        return Organization::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Organization $organization): array => [
                'id' => (int) $organization->getKey(),
                'name' => (string) $organization->name,
            ])
            ->all();
    }
}
