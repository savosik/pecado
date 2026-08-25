<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Finance\FinanceFilters;
use App\Services\Crm\Finance\PaymentForecast;
use App\Services\Crm\Finance\ReconciliationService;
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

        // «На дату», а не период: баланс — состояние, а не оборот. Диапазон здесь
        // читался бы как «сальдо за июль», чего не бывает: сальдо всегда на момент.
        $asOf = $this->balancesDate($request);

        return Inertia::render('Crm/Pages/Finance/Balances', [
            'balances' => $this->forecast->balances($clients, $asOf),
            'asOf' => $asOf?->toDateString(),
            // Сводка нужна ради одной строки сверки в шапке: просрочка по данным 1С
            // против нашего расчёта по графику. Построчно её больше не показываем —
            // это разные разрезы (1С считает по контрагенту, график по документу),
            // и рядом в таблице они читались как ошибка.
            'summary' => $this->forecast->summary($clients, $filters),
            ...$this->sharedOptions($request, $filters),
        ]);
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
