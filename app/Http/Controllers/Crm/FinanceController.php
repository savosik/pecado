<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\Finance\FinanceFilters;
use App\Services\Crm\Finance\PartnerFinanceSnapshot;
use App\Services\Crm\Finance\PaymentForecast;
use App\Services\Crm\Finance\PaymentPlanService;
use App\Services\Crm\Finance\ReconciliationService;
use App\Services\SimpleXlsxExporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
    use Concerns\ListsOrganizations;

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
    public function plan(
        Request $request,
        PaymentPlanService $plan,
        PartnerFinanceSnapshot $snapshot,
    ): InertiaResponse {
        $today = CarbonImmutable::today();
        $view = $request->input('view') === 'calendar' ? 'calendar' : 'period';
        $month = $this->planMonth($request, $today);

        // У календаря период задаёт сам месяц, у списка — фильтры. Умолчание
        // раздела «90 дней вперёд» здесь врёт: график 1С кончается примерно
        // через месяц, и две трети окна были бы пустыми не потому, что денег
        // не ждут, а потому, что строк ещё нет.
        $filters = $view === 'calendar'
            ? FinanceFilters::fromRequest($request)->withRange($month, $month->endOfMonth())
            : $this->planPeriod($request, $today);

        $clients = $this->visibleClients($request, $filters);

        // Просрочка считается на начало периода, а не на сегодня: листая назад,
        // финансист должен видеть картину того времени, иначе прошлый месяц
        // задним числом выглядел бы хуже, чем был.
        $asOf = $filters->dateFrom->greaterThan($today) ? $today : $filters->dateFrom;

        $summary = $plan->summary($clients, $filters);
        $partners = $view === 'period' ? $plan->byPartner($clients, $filters) : [];
        $day = $this->planDay($request, $filters);
        $dayPlan = $day !== null ? $plan->dayPlan($clients, $filters, $day) : [];
        $dayFacts = $day !== null ? $plan->dayFacts($clients, $filters, $day) : [];

        $showLines = $request->input('group') === 'none';
        $rows = $showLines ? $this->planLines($clients, $filters, $today) : null;

        return Inertia::render('Crm/Pages/Finance/Plan', [
            'view' => $view,
            'today' => $today->toDateString(),
            'summary' => $summary,
            'overdue' => $plan->overdueBefore($clients, $filters, $asOf),
            'partners' => $partners,
            'snapshots' => $snapshot->for(
                $this->planPartnerIds($partners, $dayPlan, $dayFacts),
                $today,
            ),
            'showLines' => $showLines,
            'rows' => $rows,
            'calendar' => $view === 'calendar'
                ? $this->planCalendar($plan, $clients, $filters, $month, $today)
                : null,
            'day' => $day?->toDateString(),
            'dayPlan' => $dayPlan,
            'dayFacts' => $dayFacts,
            ...$this->planShared($request, $filters, $view, $month),
        ]);
    }

    /**
     * Период списка: по умолчанию от сегодня до конца месяца.
     *
     * Столько же берёт плитка пульта, и при первом открытии числа на двух
     * экранах обязаны совпадать — иначе раздел снова начнут сверять руками.
     */
    private function planPeriod(Request $request, CarbonImmutable $today): FinanceFilters
    {
        $filters = FinanceFilters::fromRequest($request);

        if ($request->input('date_from') === null && $request->input('date_to') === null) {
            return $filters->withRange($today, $today->endOfMonth());
        }

        return $filters;
    }

    /** Месяц календаря; мусор в адресе возвращает к текущему. */
    private function planMonth(Request $request, CarbonImmutable $today): CarbonImmutable
    {
        $raw = (string) $request->input('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01')->startOfMonth();
            } catch (\Throwable) {
                // Мусор календарь не роняет.
            }
        }

        return $today->startOfMonth();
    }

    /** Выбранный день детализации — только внутри показываемого периода. */
    private function planDay(Request $request, FinanceFilters $filters): ?CarbonImmutable
    {
        $raw = (string) $request->input('day', '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $day->betweenIncluded($filters->dateFrom, $filters->dateTo) ? $day : null;
    }

    /**
     * Числа календаря: план и факт по дням месяца.
     *
     * @param  Builder<User>  $clients
     * @return array<string, mixed>
     */
    private function planCalendar(
        PaymentPlanService $plan,
        Builder $clients,
        FinanceFilters $filters,
        CarbonImmutable $month,
        CarbonImmutable $today,
    ): array {
        return [
            'month' => $month->format('Y-m'),
            'month_label' => $this->planMonthLabel($month),
            'prev_month' => $month->subMonth()->format('Y-m'),
            'next_month' => $month->addMonth()->format('Y-m'),
            'days' => $plan->days($clients, $filters, $month, $month->endOfMonth()),
        ];
    }

    /** «сентябрь 2026» — заголовок сетки месяца. */
    private function planMonthLabel(CarbonImmutable $month): string
    {
        return self::MONTHS_FULL[(int) $month->format('n') - 1].' '.$month->format('Y');
    }

    /**
     * Партнёры, для которых нужен финансовый снимок: таблица плюс открытый день.
     *
     * Список собирается только из строк, прошедших скоуп менеджера: снимок
     * принимает готовые id и сам изоляцию не проверяет.
     *
     * @param  list<array<string, mixed>>  $partners
     * @param  list<array<string, mixed>>  $dayPlan
     * @param  list<array<string, mixed>>  $dayFacts
     * @return list<int>
     */
    private function planPartnerIds(array $partners, array $dayPlan, array $dayFacts): array
    {
        return array_values(array_unique([
            ...array_column($partners, 'user_id'),
            ...array_column($dayPlan, 'user_id'),
            ...array_column($dayFacts, 'user_id'),
        ]));
    }

    /**
     * Общие пропсы раздела: отбор плюс вид и месяц.
     *
     * @return array<string, mixed>
     */
    private function planShared(
        Request $request,
        FinanceFilters $filters,
        string $view,
        CarbonImmutable $month,
    ): array {
        $shared = $this->sharedOptions($request, $filters);
        $shared['filters']['view'] = $view;
        $shared['filters']['month'] = $month->format('Y-m');
        $shared['filters']['day'] = $request->input('day');
        $shared['filters']['group'] = $request->input('group') === 'none' ? 'none' : null;

        return $shared;
    }

    /**
     * Построчный список плана за выбранный период.
     *
     * @param  Builder<User>  $clients
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function planLines(Builder $clients, FinanceFilters $filters, CarbonImmutable $today): LengthAwarePaginator
    {
        $query = $this->forecast->dueBetween(
            $this->forecast->plannedQuery($clients, $filters),
            $filters->dateFrom->toDateString(),
            $filters->dateTo->toDateString(),
        );

        /** @var LengthAwarePaginator<int, object> $rows */
        $rows = $this->forecast->applyDefaultOrder($query)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->through(fn (object $row): array => $this->forecast->row($row, $today));

        return $rows;
    }

    /**
     * Выгрузка плана: итоги, ожидания по партнёрам и строки графика.
     *
     * Отдельно от общей выгрузки раздела: у той свои фильтры и свой вопрос
     * («сколько денег ждём по отделу»), а здесь — юридический план выбранного
     * периода с разделением на отгруженное и счета. Смешав их, мы получили бы
     * лист, который меняется от фильтров, к нему не относящихся.
     *
     * GET /crm/finance/plan/export
     */
    public function planExport(
        Request $request,
        PaymentPlanService $plan,
        SimpleXlsxExporter $exporter,
    ): StreamedResponse {
        $today = CarbonImmutable::today();
        $filters = $this->planPeriod($request, $today);
        $clients = $this->visibleClients($request, $filters);
        $asOf = $filters->dateFrom->greaterThan($today) ? $today : $filters->dateFrom;

        $summary = $plan->summary($clients, $filters);
        $overdue = $plan->overdueBefore($clients, $filters, $asOf);
        $partners = $plan->byPartner($clients, $filters);

        return $exporter->streamSheets('crm-plan-'.$today->format('Y-m-d'), [
            [
                'title' => 'Итоги',
                'headers' => ['Показатель', 'Значение, ₽', 'Комментарий'],
                'rows' => [
                    ['Период', null, $filters->dateFrom->format('d.m.Y').' — '.$filters->dateTo->format('d.m.Y')],
                    ['Ожидается по графику', $summary['total'], $summary['documents'].' документов, '.$summary['lines'].' строк'],
                    ['Просрочено на начало периода', $overdue['total'], $overdue['lines'].' строк, старейшая '.$overdue['oldest_days'].' дн.'],
                    ['График 1С заканчивается', null, $summary['horizon_label'] ?? 'плановых строк нет'],
                ],
            ],
            [
                'title' => 'От кого ждём',
                'headers' => ['Партнёр', 'Менеджер', 'Ждём в периоде, ₽', 'Документов'],
                'rows' => array_map(static fn (array $row): array => [
                    $row['title'],
                    $row['manager_name'],
                    $row['total'],
                    $row['documents'],
                ], $partners),
            ],
            [
                'title' => 'Строки графика',
                'headers' => [
                    'Плановая дата', 'Дней до срока', 'Дней просрочки', 'Партнёр', 'Менеджер',
                    'Организация', 'Реализация', 'Дата реализации', 'Счёт-фактура',
                    'Сумма документа', 'Сумма платежа', 'Оплачено', 'Остаток, ₽',
                    'Валюта', 'Остаток в валюте', 'Этап оплаты',
                ],
                'rows' => $this->detailSheet($clients, $filters, $today),
            ],
        ]);
    }

    /**
     * Просрочка — те же строки, но с прошедшей плановой датой. GET /crm/finance/overdue
     */
    public function overdue(Request $request): InertiaResponse
    {
        $filters = FinanceFilters::fromRequest($request);
        $clients = $this->visibleClients($request, $filters);
        $today = CarbonImmutable::today();

        // Разрез — форма отчёта, отбор — что в него попадает: то же разделение,
        // что в балансах, и те же самые разрезы.
        //
        // По умолчанию раздел открывается разрезом «партнёр → контрагент»:
        // первый вопрос к просрочке — «кто должен», а не «какие строки»;
        // построчный список нужен уже после того, как виноватый найден.
        // Он выбирается явным `group=none` — иначе отличить «показать строки»
        // от «параметр не передали» было бы нечем.
        $group = (string) $request->input('group', 'partner');
        $group = match (true) {
            $group === 'none' => '',
            isset(self::BALANCE_VIEWS[$group]) => $group,
            default => 'partner',
        };

        $query = $this->forecast->applyOverdueFilters(
            $this->forecast->overdueOnly($this->forecast->plannedQuery($clients, $filters), $today),
            $filters,
            $today,
        );

        /** @var LengthAwarePaginator<int, object> $rows */
        $rows = $this->overdueOrder($query, $request)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->through(fn (object $row): array => $this->forecast->row($row, $today));
        $this->attachLastPayments($rows, $today);

        $shared = $this->sharedOptions($request, $filters);

        // Разрез и сортировка возвращаются в снимке фильтров: панель отборов
        // переносит их между запросами по этому снимку, и без них клик по
        // «Менеджеру» сбрасывал бы и разрез, и порядок строк.
        $shared['filters']['group'] = $group === '' ? 'none' : $group;
        $shared['filters']['sort'] = $this->overdueSort($request)['column'];
        $shared['filters']['direction'] = $this->overdueSort($request)['direction'];

        return Inertia::render('Crm/Pages/Finance/Overdue', [
            'rows' => $rows,
            // Итог по текущему отбору, а не по всей просрочке: цифра в шапке
            // обязана совпадать с тем, что видно в таблице под ней.
            'totals' => $this->overdueTotals($query),
            'aging' => $this->forecast->aging($clients, $filters),
            'group' => $group,
            'groups' => array_map(
                static fn (array $preset, string $key): array => ['value' => $key, 'label' => $preset['label']],
                array_values(self::BALANCE_VIEWS),
                array_keys(self::BALANCE_VIEWS),
            ),
            'groupRows' => $group !== ''
                ? $this->forecast->overdueTree($clients, $filters, self::BALANCE_VIEWS[$group]['dimensions'])
                : null,
            // Общий долг по тому же отбору: доля просрочки в нём — главный
            // индикатор раздела, и считать её на клиенте из разных источников
            // значило бы получить два разных числа на одном экране.
            'debtTotal' => -$this->forecast->debtTotal($clients, $filters->organizationIds),
            'sort' => $this->overdueSort($request),
            ...$shared,
        ]);
    }

    /**
     * Итоги по текущему отбору: сумма, строки, документы, партнёры.
     *
     * Считаются отдельным агрегатом, а не по странице пагинации: «просрочено
     * всего» на второй странице не должно меняться.
     *
     * @return array<string, float|int>
     */
    private function overdueTotals(QueryBuilder $query): array
    {
        $rows = (clone $query)->reorder()
            ->select('sch.currency_code')
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as unpaid')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('COUNT(DISTINCT sch.document_uuid) as documents_count')
            ->selectRaw('COUNT(DISTINCT sch.user_id) as clients_count')
            ->selectRaw('SUM((sch.amount - sch.settled_amount) * '.$this->overdueDaysExpression().') as weight')
            ->groupBy('sch.currency_code')
            ->get();

        $amount = 0.0;
        $weight = 0.0;
        $lines = 0;
        $documents = 0;
        $clients = 0;

        foreach ($rows as $row) {
            $amount += $this->forecast->toRub((float) $row->unpaid, $row->currency_code);
            $weight += $this->forecast->toRub((float) $row->weight, $row->currency_code);
            $lines += (int) $row->lines_count;
            $documents += (int) $row->documents_count;
            $clients = max($clients, (int) $row->clients_count);
        }

        return [
            'amount' => round($amount, 2),
            'weight' => round($weight, 2),
            // Средневзвешенный возраст просрочки: рублёдни на рубль долга.
            // Отвечает на «сколько в среднем ждём эти деньги» одним числом.
            'weighted_age' => $amount > 0.01 ? (int) round($weight / $amount) : 0,
            'lines' => $lines,
            'documents' => $documents,
            'clients' => $clients,
        ];
    }

    /**
     * Дата последнего платежа партнёра — к строкам текущей страницы.
     *
     * Одним запросом по видимым партнёрам, а не подзапросом в основном:
     * страница показывает полсотни строк, а партнёров за ними десяток, и
     * тянуть максимум по каждой строке значило бы считать одно и то же.
     *
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $rows
     */
    private function attachLastPayments(LengthAwarePaginator $rows, CarbonImmutable $today): void
    {
        $userIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['client']['id'],
            $rows->items(),
        )));

        if ($userIds === []) {
            return;
        }

        $payments = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->where('type', SettlementEntry::TYPE_PAYMENT_IN)
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(date) as last_payment_date')
            ->pluck('last_payment_date', 'user_id');

        $rows->setCollection($rows->getCollection()->map(static function (array $row) use ($payments, $today): array {
            $date = $payments[$row['client']['id']] ?? null;

            $row['last_payment_date'] = $date !== null
                ? CarbonImmutable::parse($date)->format('d.m.Y')
                : null;
            $row['days_since_payment'] = $date !== null
                ? (int) CarbonImmutable::parse($date)->diffInDays($today)
                : null;

            return $row;
        }));
    }

    /** Число просроченных дней строки — выражение под текущий драйвер БД. */
    private function overdueDaysExpression(): string
    {
        $today = CarbonImmutable::today()->toDateString();

        return DB::connection()->getDriverName() === 'sqlite'
            ? "(julianday('".$today."') - julianday(sch.date))"
            : "DATEDIFF('".$today."', sch.date)";
    }

    /**
     * Сортировка списка: по сроку (самые давние сверху), по сумме или по
     * партнёру. Ключи те же, что у колонок таблицы.
     *
     * @return array{column: string, direction: string}
     */
    private function overdueSort(Request $request): array
    {
        $column = (string) $request->input('sort', 'due_date');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        return [
            'column' => in_array($column, ['due_date', 'unpaid', 'client', 'weight'], true) ? $column : 'due_date',
            'direction' => $direction,
        ];
    }

    private function overdueOrder(QueryBuilder $query, Request $request): QueryBuilder
    {
        ['column' => $column, 'direction' => $direction] = $this->overdueSort($request);

        $days = $this->overdueDaysExpression();

        return match ($column) {
            // Вес — «рублёдни»: остаток, умноженный на дни просрочки. Сверху
            // оказывается не самое крупное и не самое давнее, а то, где
            // сумма и время вместе дают наибольшую цену ожидания.
            'weight' => $query->reorder()
                ->orderByRaw('(sch.amount - sch.settled_amount) * '.$days.' '.$direction)
                ->orderBy('sch.id'),
            // Остаток сортируется в валюте строки: рублёвый эквивалент считается
            // после выборки, и упорядочить по нему в SQL нечем. Валютных строк
            // в просрочке единицы, порядок от этого практически не страдает.
            'unpaid' => $query->reorder()->orderByRaw('sch.amount - sch.settled_amount '.$direction)->orderBy('sch.id'),
            'client' => $query->reorder()->orderByRaw('COALESCE(NULLIF(u.erp_name, \'\'), u.name) '.$direction)->orderBy('sch.date'),
            default => $query->reorder()->orderBy('sch.date', $direction)->orderBy('sch.id'),
        };
    }

    /**
     * Балансы партнёров из 1С. GET /crm/finance/balances
     */
    /**
     * Готовые разрезы балансов.
     *
     * Свободный выбор осей в интерфейсе не даём: из шести перестановок трёх
     * измерений осмысленны четыре, а остальные («контрагент → партнёр»)
     * повторяют друг друга — у контрагента партнёр всегда один.
     */
    private const BALANCE_VIEWS = [
        'partner' => ['label' => 'Партнёр → контрагент', 'dimensions' => ['partner', 'company']],
        'partner_org' => ['label' => 'Партнёр → организация → контрагент', 'dimensions' => ['partner', 'organization', 'company']],
        'org' => ['label' => 'Наша организация → контрагент', 'dimensions' => ['organization', 'company']],
        // Обратный разрез: у контрагента расчёты могут идти сразу с несколькими
        // нашими юрлицами, и вопрос «с кем именно из наших он не рассчитался»
        // из группировки по организации сверху не читается.
        'company_org' => ['label' => 'Контрагент → наша организация', 'dimensions' => ['company', 'organization']],
        'company' => ['label' => 'Контрагенты списком', 'dimensions' => ['company']],
        // Наша организация сверху, партнёры под ней: «перед каким нашим юрлицом
        // висит долг и чей он» — вопрос бухгалтерии, а не менеджера.
        'org_partner' => ['label' => 'Наша организация → партнёр', 'dimensions' => ['organization', 'partner']],
        'company_org_manager' => [
            'label' => 'Контрагент → организация → менеджер',
            'dimensions' => ['company', 'organization', 'manager'],
        ],
        // Разрезы отдела: РОП смотрит на долг не по клиентам, а по людям —
        // сколько ведёт каждый менеджер и с какими нашими юрлицами это идёт.
        'manager' => ['label' => 'Менеджер → партнёр → контрагент', 'dimensions' => ['manager', 'partner', 'company']],
        'manager_org' => ['label' => 'Менеджер → организация → партнёр', 'dimensions' => ['manager', 'organization', 'partner']],
    ];

    public function balances(Request $request): InertiaResponse
    {
        $filters = FinanceFilters::fromRequest($request);
        $clients = $this->visibleClients($request, $filters);

        // «На дату», а не период: баланс — состояние, а не оборот. Диапазон здесь
        // читался бы как «сальдо за июль», чего не бывает: сальдо всегда на момент.
        $asOf = $this->balancesDate($request);
        $dimensions = $this->balancesDimensions($request);

        return Inertia::render('Crm/Pages/Finance/Balances', [
            // Отбор применяется до разреза: фильтры сужают ленту движений,
            // разрез только раскладывает то, что осталось.
            'balances' => $this->forecast->balances($clients, $asOf, $dimensions, $filters->organizationIds),
            'asOf' => $asOf?->toDateString(),
            // Ключ разреза отдаём как есть: экран рисует переключатель по нему,
            // а не по составу осей — иначе подпись пришлось бы собирать на клиенте.
            'view' => (string) $request->input('view', 'partner'),
            'views' => array_map(
                static fn (array $preset, string $key): array => ['value' => $key, 'label' => $preset['label']],
                array_values(self::BALANCE_VIEWS),
                array_keys(self::BALANCE_VIEWS),
            ),
            // Сводка нужна ради одной строки сверки в шапке: просрочка по данным 1С
            // против нашего расчёта по графику. Построчно её больше не показываем —
            // это разные разрезы (1С считает по контрагенту, график по документу),
            // и рядом в таблице они читались как ошибка.
            'summary' => $this->forecast->summary($clients, $filters),
            ...$this->sharedOptions($request, $filters),
        ]);
    }

    /**
     * Разрез отчёта: оси в порядке вложенности.
     *
     * Приходит строкой вида `organization,company` — так разрез переживает
     * закладку и «назад», а массив в адресе выглядел бы шумом.
     *
     * @return list<string>
     */
    private function balancesDimensions(Request $request): array
    {
        $raw = (string) $request->input('view', '');
        $preset = self::BALANCE_VIEWS[$raw] ?? null;

        return $preset['dimensions'] ?? ['partner', 'company'];
    }

    /**
     * Дата отчёта по балансам. Мусор и будущее гасятся в «сейчас»:
     * баланс на завтра — то же, что баланс сегодня, а показывать его датой
     * из будущего значит обещать читателю больше, чем отчёт знает.
     */
    private function balancesDate(Request $request): ?CarbonImmutable
    {
        $value = trim((string) $request->input('as_of'));

        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->isFuture() ? null : $date;
    }

    /**
     * Акт сверки взаиморасчётов. GET /crm/finance/reconciliation
     *
     * Клиент выбирается явно, а не берётся из фильтров раздела: акт — документ
     * по одному контрагенту, и «акт по всем клиентам менеджера» не имеет смысла.
     * Пока клиент не выбран, страница показывает только форму.
     */
    public function reconciliation(Request $request, ReconciliationService $service): InertiaResponse
    {
        $period = $service->defaultPeriod();
        $from = (string) $request->string('date_from', $period['from']);
        $to = (string) $request->string('date_to', $period['to']);
        $companyId = $request->integer('company_id') ?: null;
        $organizationId = $request->integer('organization_id') ?: null;

        [$client, $companyId, $notice] = $this->resolveReconciliationParties($request, $service, $companyId);
        $clients = $this->visibleClientsForReconciliation($request);

        return Inertia::render('Crm/Pages/Finance/Reconciliation', [
            'client' => $client !== null ? [
                'id' => (int) $client->getKey(),
                'name' => $client->erp_name ?: $client->name,
                'url' => route('crm.clients.show', $client->getKey()),
            ] : null,
            'act' => $client !== null ? $service->act(
                client: $client,
                organizationId: $organizationId,
                from: $from,
                to: $to,
                currency: (string) $request->string('currency', 'RUB'),
                companyId: $companyId,
            ) : null,
            'options' => [
                // Список партнёров нужен всегда: без него не с чего начать.
                // Уже ограничен скоупом менеджера, поэтому чужих в нём нет.
                'clients' => $this->clientOptions($request),
                // Контрагенты и организации доступны до выбора партнёра: акт
                // часто начинают с юрлица («пришлите сверку по ООО „Ромашка“»).
                // Выбранный партнёр сужает оба справочника до своих.
                'companies' => $client !== null
                    ? $service->companiesOf($client)
                    : $service->companiesInScope($clients),
                'organizations' => $client !== null
                    ? $service->organizationsOf($client)
                    : $service->organizationsInScope($clients),
                'currencies' => $client !== null ? $service->currenciesOf($client) : ['RUB'],
            ],
            // Снятый фильтр объясняется словами: молча проигнорированный выбор
            // выглядит как поломка экрана.
            'notice' => $notice,
            'form' => [
                // client_id возвращается уже разрешённым: выбрали контрагента —
                // партнёр подставился сам, и экран показывает его выбранным.
                'client_id' => $client?->getKey(),
                'company_id' => $companyId,
                'organization_id' => $organizationId,
                'currency' => (string) $request->string('currency', 'RUB'),
                'date_from' => $from,
                'date_to' => $to,
            ],
        ]);
    }

    /**
     * Партнёр и контрагент акта: что выбрал менеджер и что из этого следует.
     *
     * Выбор контрагента доводится до партнёра, потому что акт строится по нему.
     * Если контрагент не принадлежит выбранному партнёру, побеждает партнёр,
     * а контрагент гасится: показать акт «партнёр А, юрлицо партнёра Б» нельзя,
     * а падать 404 на несочетаемый отбор — значит наказывать за любопытство.
     *
     * @return array{0: ?User, 1: ?int, 2: ?string}
     */
    private function resolveReconciliationParties(
        Request $request,
        ReconciliationService $service,
        ?int $companyId,
    ): array {
        $clientId = $request->integer('client_id') ?: null;
        $clients = $this->visibleClientsForReconciliation($request);

        // visibleInCrm, а не findOrFail: чужой клиент обязан давать 404,
        // а не показывать акт по деньгам другого менеджера.
        $client = $clientId !== null
            ? User::query()->visibleInCrm($this->crmActor($request))->find($clientId)
            : null;

        if ($clientId !== null && $client === null) {
            abort(404);
        }

        if ($companyId === null) {
            return [$client, null, null];
        }

        $ownerOfCompany = $service->clientOfCompany($clients, $companyId);

        if ($client === null) {
            // Контрагент без партнёра: партнёр выводится из движений юрлица.
            // Не вывелся (юрлицо у нескольких партнёров или чужое) — контрагент
            // гасится, и экран остаётся формой, а не отдаёт чужие деньги.
            return $ownerOfCompany !== null
                ? [$ownerOfCompany, $companyId, null]
                : [null, null, 'Этот контрагент встречается у нескольких партнёров или недоступен вам — выберите партнёра.'];
        }

        return $ownerOfCompany !== null && $ownerOfCompany->getKey() === $client->getKey()
            ? [$client, $companyId, null]
            : [$client, null, 'Выбранный контрагент относится к другому партнёру — фильтр по нему снят.'];
    }

    /**
     * Партнёры, доступные актору, — набором для справочников акта.
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function visibleClientsForReconciliation(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->visibleInCrm($this->crmActor($request))->select('users.id');
    }

    /**
     * Выгрузка акта сверки. GET /crm/finance/reconciliation/export
     *
     * Отдельной кнопкой, а не листом общей выгрузки раздела: у той свои фильтры —
     * период, менеджеры, организации, — и она отвечает на вопрос «сколько денег
     * ждём по отделу». Акт же строится по одному клиенту и одному нашему юрлицу,
     * и подмешать его туда значило бы получить лист, который меняется от фильтров,
     * к нему не относящихся.
     */
    public function reconciliationExport(
        Request $request,
        ReconciliationService $service,
        SimpleXlsxExporter $exporter,
    ): StreamedResponse {
        $organizationId = $request->integer('organization_id') ?: null;

        // Тот же разбор сторон, что и на экране: выгрузка обязана повторять
        // видимое, включая партнёра, выведенного из выбранного контрагента.
        [$client, $companyId] = $this->resolveReconciliationParties(
            $request,
            $service,
            $request->integer('company_id') ?: null,
        );

        abort_if($client === null, 404);

        $period = $service->defaultPeriod();
        $act = $service->act(
            client: $client,
            organizationId: $organizationId,
            from: (string) $request->string('date_from', $period['from']),
            to: (string) $request->string('date_to', $period['to']),
            currency: (string) $request->string('currency', 'RUB'),
            companyId: $companyId,
        );

        // Колонки сторон — только когда сторона не задана фильтром: в акте
        // по одному юрлицу колонка с его повторяющимся именем лишь занимает
        // ширину, а в акте по всем — единственный способ понять, чьи это строки.
        $withCompany = $companyId === null;
        $withOrganization = $organizationId === null;

        $headers = array_values(array_filter([
            'Дата',
            'Документ',
            'Операция',
            $withCompany ? 'Контрагент' : null,
            // В файле, который уходит клиенту, «наша организация» звучит с чужой
            // стороны стола: для получателя мы — поставщик. На экране заголовок
            // остаётся прежним, там читатель свой.
            $withOrganization ? 'Поставщик' : null,
            'Дебет',
            'Кредит',
            'Сальдо',
        ]));

        return $exporter->streamSheets(
            'akt-sverki-'.$client->getKey().'-'.$act['period']['from'].'-'.$act['period']['to'],
            [[
                'title' => 'Акт сверки',
                'headers' => $headers,
                'rows' => $this->reconciliationSheet($client, $act, $withCompany, $withOrganization),
            ]],
        );
    }

    /**
     * Лист акта: шапка с сальдо на начало, движения, обороты и сальдо на конец.
     *
     * Итоги строками того же листа, а не отдельной вкладкой: акт печатают
     * и отправляют клиенту одним куском, и сальдо, оторванное от движений,
     * пришлось бы сводить вручную.
     *
     * @param  array<string, mixed>  $act
     * @return list<array<int, scalar|null>>
     */
    private function reconciliationSheet(
        User $client,
        array $act,
        bool $withCompany = true,
        bool $withOrganization = true,
    ): array {
        // Ширина листа плавает вместе с набором колонок сторон, поэтому строки
        // шапки и итогов собираются добивкой до неё, а не руками по месту.
        $width = 6 + (int) $withCompany + (int) $withOrganization;
        $line = static fn (array $cells): array => array_pad($cells, $width, null);
        $last = static function (array $head, mixed $tail) use ($width): array {
            $row = array_pad($head, $width - 1, null);
            $row[] = $tail;

            return $row;
        };

        $rows = [
            $line(['Клиент', $client->erp_name ?: $client->name]),
            $line(['Период', $act['period']['from'].' — '.$act['period']['to']]),
            $line(['Валюта', $act['currency']]),
            $last(['Сальдо на начало'], $act['opening_balance']),
            $line([]),
        ];

        if ($act['discrepancy'] !== null) {
            // Предупреждение в самом файле, а не только на экране: выгрузку
            // отправляют клиенту, и расхождение обязано ехать вместе с ней.
            $rows[] = $line([
                'ВНИМАНИЕ',
                'Сумма движений не сходится с балансом 1С на '.$act['discrepancy']['delta']
                    .' — акт неполный, отправлять клиенту нельзя',
            ]);
            $rows[] = $line([]);
        }

        foreach ($act['rows'] as $row) {
            $cells = [$row['date_label'], $row['document'], $row['type_label']];

            if ($withCompany) {
                $cells[] = $row['company_name'] ?? '—';
            }

            if ($withOrganization) {
                $cells[] = $row['organization_name'] ?? '—';
            }

            $cells[] = $row['debit'] ?: null;
            $cells[] = $row['credit'] ?: null;
            $cells[] = $row['balance'];

            $rows[] = $cells;
        }

        $turnover = array_pad(['Обороты за период'], $width - 3, null);
        $turnover[] = $act['turnover_debit'];
        $turnover[] = $act['turnover_credit'];
        $turnover[] = null;

        $rows[] = $line([]);
        $rows[] = $turnover;
        $rows[] = $last(['Сальдо на конец'], $act['closing_balance']);

        if ($act['truncated']) {
            $rows[] = $line([
                'ВНИМАНИЕ',
                'Показаны первые '.$act['rows_count'].' движений — сальдо на конец неполное. Сузьте период',
            ]);
        }

        return $rows;
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
                'rows' => $this->balancesSheet($clients, $filters),
            ],
        ]);
    }

    /**
     * Лист «Балансы»: строка на контрагента, имя партнёра дублируется в каждой —
     * так лист фильтруется и сводится в самом Excel.
     *
     * Выгрузка всегда идёт в разрезе «партнёр → контрагент» независимо от того,
     * какой разрез выбран на экране: лист сверяют с 1С, а она ведёт расчёты
     * именно по контрагентам, и переменная форма листа ломала бы сводные.
     *
     * @param  Builder<User>  $clients
     * @return list<array<int, scalar|null>>
     */
    private function balancesSheet(Builder $clients, FinanceFilters $filters): array
    {
        $rows = [];

        foreach ($this->forecast->balances($clients, null, ['partner', 'company'], $filters->organizationIds) as $partner) {
            // Партнёр без контрагентов в дереве листьев не имеет — его строку
            // всё равно выводим, иначе сальдо листа не сойдётся с экраном.
            $contractors = $partner['children'] ?: [$partner];

            foreach ($contractors as $contractor) {
                $rows[] = [
                    $partner['title'],
                    $partner['manager_name'],
                    $contractor['title'],
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
    /**
     * Пульт: выжимка для руководителя, который не ведёт клиентов.
     *
     * Пять чисел на пять вопросов — сколько должны, сколько просрочено,
     * сколько придёт, сколько уже пришло и кто главные должники. Всё
     * остальное живёт в своих разделах: пульт, набитый построчными
     * таблицами, перестаёт быть пультом.
     *
     * @return array<string, mixed>
     */
    private function dashboardPayload(Request $request): array
    {
        $filters = FinanceFilters::fromRequest($request);
        $clients = $this->visibleClients($request, $filters);
        $today = CarbonImmutable::today();

        $summary = $this->forecast->summary($clients, $filters);
        $debt = $this->forecast->debtTotal($clients, $filters->organizationIds);

        $overdue = round((float) ($summary['overdue_amount'] ?? 0), 2);

        return [
            'money' => [
                'debt' => $debt,
                'overdue' => $overdue,
                'overdue_share' => $debt > 0 ? (int) round($overdue / $debt * 100) : 0,
                'overdue_clients' => (int) ($summary['overdue_clients'] ?? 0),
                // Ожидание по графику 1С, а не оценка сбора: то же число, что
                // на экране «План поступлений», иначе пульт и раздел начнут
                // расходиться и их снова примутся сверять руками.
                'expected_30' => round((float) ($summary['expected_30'] ?? 0), 2),
                'month' => $this->monthFact($clients, $filters, $today),
            ],
            'history' => $this->monthlyHistory($clients, $filters, $today),
            'debtors' => $this->forecast->topDebtors($clients, $filters, 5),
            'aging' => $this->forecast->aging($clients, $filters),
            ...$this->sharedOptions($request, $filters),
        ];
    }

    /**
     * Деньги текущего месяца: сколько уже пришло и как это выглядит на фоне
     * обычного месяца.
     *
     * @param  Builder<User>  $clients
     * @return array<string, float|int>
     */
    private function monthFact(Builder $clients, FinanceFilters $filters, CarbonImmutable $today): array
    {
        $history = $this->monthlyHistory($clients, $filters, $today);
        $closed = array_values(array_filter(
            $history,
            static fn (array $row): bool => ! $row['current'],
        ));

        $amounts = array_column($closed, 'amount');
        sort($amounts);
        $typical = $amounts === [] ? 0.0 : $amounts[(int) floor(count($amounts) / 2)];

        $current = array_values(array_filter($history, static fn (array $row): bool => $row['current']));
        $received = $current === [] ? 0.0 : $current[0]['amount'];

        return [
            'received' => round($received, 2),
            'typical' => round($typical, 2),
            'days_passed' => (int) $today->startOfMonth()->diffInDays($today) + 1,
            'days_total' => $today->endOfMonth()->day,
        ];
    }

    /**
     * Поступления по месяцам за полгода — единственный график пульта.
     *
     * @param  Builder<User>  $clients
     * @return list<array{month: string, label: string, amount: float, current: bool}>
     */
    private function monthlyHistory(Builder $clients, FinanceFilters $filters, CarbonImmutable $today): array
    {
        $from = $today->startOfMonth()->subMonths(5);
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', date)"
            : "DATE_FORMAT(date, '%Y-%m')";

        $rows = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('type', [SettlementEntry::TYPE_PAYMENT_IN, SettlementEntry::TYPE_PAYMENT_OUT])
            ->whereIn('user_id', (clone $clients))
            ->when(
                $filters->organizationIds !== [],
                fn ($query) => $query->whereIn('organization_id', $filters->organizationIds),
            )
            ->whereDate('date', '>=', $from->toDateString())
            ->groupByRaw($expression)
            ->selectRaw($expression.' as month')
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as amount')
            ->pluck('amount', 'month');

        $months = [];

        for ($cursor = $from; $cursor->lessThanOrEqualTo($today); $cursor = $cursor->addMonth()) {
            $key = $cursor->format('Y-m');

            $months[] = [
                'month' => $key,
                'label' => self::MONTHS_SHORT[$cursor->month - 1],
                'amount' => round((float) ($rows[$key] ?? 0), 2),
                'current' => $key === $today->format('Y-m'),
            ];
        }

        return $months;
    }

    /** @var list<string> */
    /** Месяцы для заголовка сетки календаря. */
    private const MONTHS_FULL = [
        'январь', 'февраль', 'март', 'апрель', 'май', 'июнь',
        'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь',
    ];

    private const MONTHS_SHORT = [
        'январь', 'февраль', 'март', 'апрель', 'май', 'июнь',
        'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь',
    ];

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
            // Через dueBetween, а не whereBetween по колонке: имя плановой даты —
            // деталь ядра (`due_date` у графика, `date` у регистра), и выгрузка
            // падала на проде 500-й, пока тесты шли на старом ядре.
            $planned = $this->forecast->dueBetween(
                $planned,
                $filters->dateFrom->toDateString(),
                $filters->dateTo->toDateString(),
            );
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
        $seesAll = $this->seesDepartment($request);

        return [
            'filters' => [
                ...$filters->toArray(),
                'scope' => CrmScope::fromRequest($request, $this->crmActor($request))->value,
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
        $query = User::query()
            ->inCrmScope($actor, CrmScope::fromRequest($request, $actor))
            ->select('users.id');

        // Отбор по менеджеру сужает уже видимое: тому, кто отдел не видит,
        // чужой id в запросе всё равно ничего не даст — скоуп отсечёт раньше.
        if ($this->seesDepartment($request) && $filters->managerIds !== []) {
            $query->whereIn('users.personal_manager_id', $filters->managerIds);
        }

        return $query;
    }

    /**
     * Партнёры для выбора в акте сверки — в пределах скоупа менеджера.
     *
     * @return list<array{id: int, name: string}>
     */
    private function clientOptions(Request $request): array
    {
        return User::query()
            ->visibleInCrm($this->crmActor($request))
            ->orderByRaw('COALESCE(NULLIF(users.erp_name, ?), users.name)', [''])
            ->get(['users.id', 'users.name', 'users.erp_name'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'name' => $user->erp_name ?: $user->name,
            ])
            ->all();
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
}
