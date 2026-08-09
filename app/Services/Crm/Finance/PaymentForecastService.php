<?php

namespace App\Services\Crm\Finance;

use App\Models\ContractorBalance;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\ShipmentPaymentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Единый расчёт ожидаемых денег для CRM: план поступлений, просрочка, балансы.
 *
 * Источник плана — график оплаты реализаций («Правила оплаты» 1С), источник факта —
 * проведённые платежи по их бизнес-дате. Оба живут здесь, а не в контроллерах:
 * календарь, пульт и выгрузка обязаны показывать одно и то же число, а два
 * независимых запроса неизбежно разъедутся.
 *
 * Все суммы сводятся в рубли. Документы приезжают из 1С в своей валюте, и сложение
 * BYN с RUB «как есть» дало бы бессмысленный итог; исходная сумма и код валюты
 * остаются в строке детализации, чтобы менеджер видел, из чего сложился рубль.
 */
class PaymentForecastService
{
    /** Корзины просрочки в днях: до какого дня включительно попадает строка. */
    private const AGING_BUCKETS = [
        ['key' => '1_7', 'label' => '1–7 дней', 'to' => 7],
        ['key' => '8_14', 'label' => '8–14 дней', 'to' => 14],
        ['key' => '15_30', 'label' => '15–30 дней', 'to' => 30],
        ['key' => '31_60', 'label' => '31–60 дней', 'to' => 60],
        ['key' => '60_plus', 'label' => 'более 60 дней', 'to' => null],
    ];

    /**
     * Курсы валют к рублю, code => exchange_rate. Читаются один раз на запрос:
     * строк плана тысячи, а справочник валют — единицы.
     *
     * @var array<string, float>|null
     */
    private ?array $rates = null;

    /**
     * Строки графика, по которым ещё ждём денег.
     *
     * Джойном, а не через связи: отчёту нужны плоские поля, а грузить реализации
     * со связями ради номера документа — лишние запросы на каждую страницу.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients  скоуп клиентов (User::visibleInCrm)
     */
    public function plannedQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        $query = DB::table('shipment_payment_schedules as sch')
            ->join('shipments as s', 's.id', '=', 'sch.shipment_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->leftJoin('organizations as org', 'org.id', '=', 's.organization_id')
            ->whereNull('s.deleted_at')
            ->whereIn('s.user_id', (clone $clients))
            // Закрытые строки в план не идут: деньги по ним уже пришли, и показывать
            // их как ожидаемые значило бы задвоить выручку. Аванс по заказу закрывает
            // строку наравне с прямым разнесением — см. scopeOutstanding. Константа
            // в SQL, а не биндингом (там же объяснение почему).
            ->whereRaw('sch.amount - sch.paid_amount - sch.prepaid_amount > '.ShipmentPaymentSchedule::EPSILON)
            ->select([
                'sch.id',
                'sch.shipment_id',
                'sch.due_date',
                'sch.amount',
                'sch.paid_amount',
                'sch.prepaid_amount',
                'sch.stage_name',
                'sch.line_number',
                's.number as shipment_number',
                's.erp_number as shipment_erp_number',
                's.date as shipment_date',
                's.invoice_number_display',
                's.total_amount as shipment_total',
                's.currency_code',
                's.user_id',
                'u.name as client_name',
                'u.erp_name as client_erp_name',
                'u.personal_manager_id',
                'pm.name as manager_name',
                'org.name as organization_name',
            ]);

        return $this->applyCommonFilters($query, $filters);
    }

    /**
     * Долг реализаций, для которых 1С не прислала график оплаты.
     *
     * Массив `payment_schedule` опционален, и без этой ветки отчёт молча занизил бы
     * ожидаемые деньги на весь такой долг. Плановой даты у строки нет — она попадает
     * в отдельный бакет «срок не определён», а не в произвольный день.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        $query = DB::table('shipments as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->leftJoin('organizations as org', 'org.id', '=', 's.organization_id')
            ->whereNull('s.deleted_at')
            ->whereIn('s.user_id', (clone $clients))
            ->whereRaw('s.total_amount - s.paid_amount > '.ShipmentPaymentSchedule::EPSILON)
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->select(DB::raw(1))
                ->from('shipment_payment_schedules as sch')
                ->whereColumn('sch.shipment_id', 's.id'))
            ->select([
                's.id',
                's.id as shipment_id',
                DB::raw('NULL as due_date'),
                's.total_amount as amount',
                's.paid_amount',
                // У реализации без графика зачитывать нечего: аванс раскладывается
                // по строкам, а строк тут нет. Колонка нужна ради единой формы строки.
                DB::raw('0 as prepaid_amount'),
                DB::raw('NULL as stage_name'),
                DB::raw('NULL as line_number'),
                's.number as shipment_number',
                's.erp_number as shipment_erp_number',
                's.date as shipment_date',
                's.invoice_number_display',
                's.total_amount as shipment_total',
                's.currency_code',
                's.user_id',
                'u.name as client_name',
                'u.erp_name as client_erp_name',
                'u.personal_manager_id',
                'pm.name as manager_name',
                'org.name as organization_name',
            ]);

        return $this->applyCommonFilters($query, $filters, withDueDate: false);
    }

    /**
     * Ожидаемые поступления по дням в пределах периода фильтров.
     *
     * Группировка в SQL, а не в PHP: строк графика на отчётный горизонт могут быть
     * тысячи, а дней — максимум сотни. Валюта в ключе группировки, потому что
     * конвертация делается уже над агрегатом.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return Collection<string, float> дата (Y-m-d) => сумма в рублях
     */
    public function dailyPlan(EloquentBuilder $clients, FinanceFilters $filters): Collection
    {
        $query = $this->plannedQuery($clients, $filters)
            ->whereBetween('sch.due_date', [$filters->dateFrom->toDateString(), $filters->dateTo->toDateString()]);

        return $this->sumByDay($query, 'sch.due_date', 'sch.amount - sch.paid_amount - sch.prepaid_amount');
    }

    /**
     * Фактические поступления по дням.
     *
     * Бизнес-дата платежа — `date`, а не `created_at`: прод импортировал историю 1С,
     * и дата создания записи на сайте к дню поступления денег отношения не имеет.
     * Возвраты вычитаются — иначе день с возвратом выглядел бы прибыльным.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return Collection<string, array{amount: float, count: int}>
     */
    public function factsByDay(EloquentBuilder $clients, string $from, string $to): Collection
    {
        $rows = DB::table('payments')
            ->whereNull('deleted_at')
            ->whereIn('user_id', (clone $clients))
            ->whereBetween(DB::raw('DATE(date)'), [$from, $to])
            ->groupBy(DB::raw('DATE(date)'), 'currency_code')
            ->selectRaw('DATE(date) as day')
            ->selectRaw('currency_code')
            ->selectRaw("SUM(CASE WHEN direction = 'out' THEN -amount ELSE amount END) as amount")
            ->selectRaw('COUNT(*) as documents')
            ->get();

        $byDay = [];

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $byDay[$day] ??= ['amount' => 0.0, 'count' => 0];
            $byDay[$day]['amount'] += $this->toRub((float) $row->amount, $row->currency_code);
            $byDay[$day]['count'] += (int) $row->documents;
        }

        return collect($byDay)->map(fn (array $day): array => [
            'amount' => round($day['amount'], 2),
            'count' => $day['count'],
        ]);
    }

    /**
     * Свод плана и факта по корзинам времени — то, из чего строится график на пульте
     * и блок «Ожидается по неделям» в выгрузке.
     *
     * @param  Collection<string, float>  $plan  день => рубли
     * @param  Collection<string, array{amount: float, count: int}>  $facts
     * @return list<array{key: string, label: string, from: string, to: string, plan: float, fact: float}>
     */
    public function buckets(Collection $plan, Collection $facts, FinanceFilters $filters): array
    {
        $buckets = [];

        $cursor = $filters->dateFrom;
        $end = $filters->dateTo;

        while ($cursor->lessThanOrEqualTo($end)) {
            [$bucketStart, $bucketEnd] = match ($filters->granularity) {
                'day' => [$cursor, $cursor],
                'month' => [$cursor->startOfMonth(), $cursor->endOfMonth()],
                default => [$cursor->startOfWeek(), $cursor->endOfWeek()],
            };

            $from = $bucketStart->lessThan($filters->dateFrom) ? $filters->dateFrom : $bucketStart;
            $to = $bucketEnd->greaterThan($end) ? $end : $bucketEnd;

            $buckets[] = [
                'key' => $from->toDateString(),
                'label' => $this->bucketLabel($from, $to, $filters->granularity),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'plan' => round($this->sumRange($plan, $from, $to, fn ($value): float => (float) $value), 2),
                'fact' => round($this->sumRange($facts, $from, $to, fn ($value): float => (float) $value['amount']), 2),
            ];

            $cursor = $bucketEnd->addDay()->startOfDay();
        }

        return $buckets;
    }

    /**
     * Просрочка по корзинам давности.
     *
     * От периода фильтров не зависит: менеджеру просрочка нужна всегда, в каком бы
     * месяце он ни смотрел план.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array{total: float, count: int, clients: int, buckets: list<array{key: string, label: string, amount: float, count: int}>}
     */
    public function aging(EloquentBuilder $clients, FinanceFilters $filters): array
    {
        $today = CarbonImmutable::today();

        $rows = $this->plannedQuery($clients, $filters)
            ->whereDate('sch.due_date', '<', $today->toDateString())
            ->get();

        $totals = array_fill_keys(array_column(self::AGING_BUCKETS, 'key'), ['amount' => 0.0, 'count' => 0]);
        $total = 0.0;
        $clientIds = [];

        foreach ($rows as $row) {
            $unpaid = $this->toRub($this->unpaidOf($row), $row->currency_code);
            $days = (int) CarbonImmutable::parse($row->due_date)->diffInDays($today);
            $key = $this->agingKey($days);

            $totals[$key]['amount'] += $unpaid;
            $totals[$key]['count']++;
            $total += $unpaid;
            $clientIds[(int) $row->user_id] = true;
        }

        return [
            'total' => round($total, 2),
            'count' => $rows->count(),
            'clients' => count($clientIds),
            'buckets' => array_map(fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'amount' => round($totals[$bucket['key']]['amount'], 2),
                'count' => $totals[$bucket['key']]['count'],
            ], self::AGING_BUCKETS),
        ];
    }

    /**
     * Балансы взаиморасчётов из 1С, сгруппированные по клиенту.
     *
     * 1С ведёт расчёты по КОНТРАГЕНТАМ, а у клиента их бывает несколько (на проде
     * 234 контрагента на 144 партнёра). Поэтому строка нижнего уровня — контрагент,
     * а верхний нужен лишь чтобы имя клиента не повторялось подряд без видимой
     * причины: суммы клиента — это сумма его контрагентов, не отдельная величина.
     *
     * `current_balance` приходит от 1С без указания валюты и подписан как рубли —
     * конвертацию здесь не делаем, иначе исказили бы то, что прислала учётная система.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function balances(EloquentBuilder $clients): array
    {
        $grouped = [];

        ContractorBalance::query()
            ->whereIn('user_id', (clone $clients))
            ->with([
                'user:id,name,erp_name,personal_manager_id',
                'user.personalManager:id,name',
                'company:id,name,tax_id',
            ])
            ->orderByDesc('overdue_debt')
            ->get()
            ->each(function (ContractorBalance $balance) use (&$grouped): void {
                $userId = (int) $balance->user_id;

                $grouped[$userId] ??= [
                    'id' => $userId,
                    'client' => [
                        'id' => $userId,
                        'name' => $balance->user->erp_name ?: $balance->user->name,
                        'url' => route('crm.clients.show', $userId),
                    ],
                    'manager_name' => $balance->user->personalManager?->name,
                    'current_balance' => 0.0,
                    'overdue_debt' => 0.0,
                    'erp_updated_at' => null,
                    'contractors' => [],
                ];

                $updatedAt = $balance->balance_erp_updated_at;

                $grouped[$userId]['current_balance'] += (float) $balance->current_balance;
                $grouped[$userId]['overdue_debt'] += (float) $balance->overdue_debt;
                // У клиента показываем самую свежую дату из его контрагентов: 1С
                // обновляет их по отдельности, и старая дата означала бы, что устарел
                // весь баланс клиента, — а это не так.
                $grouped[$userId]['erp_updated_at'] = max(
                    $grouped[$userId]['erp_updated_at'],
                    $updatedAt?->format('Y-m-d H:i:s'),
                );

                $grouped[$userId]['contractors'][] = [
                    'id' => (int) $balance->getKey(),
                    'company_name' => $balance->company?->name,
                    'tax_id' => $balance->tax_id,
                    'current_balance' => round((float) $balance->current_balance, 2),
                    'overdue_debt' => round((float) $balance->overdue_debt, 2),
                    'erp_updated_at' => $updatedAt?->format('d.m.Y H:i'),
                ];
            });

        $rows = array_map(static function (array $row): array {
            $row['current_balance'] = round($row['current_balance'], 2);
            $row['overdue_debt'] = round($row['overdue_debt'], 2);
            $row['erp_updated_at'] = $row['erp_updated_at'] !== null
                ? CarbonImmutable::parse($row['erp_updated_at'])->format('d.m.Y H:i')
                : null;

            return $row;
        }, array_values($grouped));

        usort($rows, static fn (array $a, array $b): int => $b['overdue_debt'] <=> $a['overdue_debt']);

        return $rows;
    }

    /**
     * Ключевые показатели пульта.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function summary(EloquentBuilder $clients, FinanceFilters $filters): array
    {
        $today = CarbonImmutable::today();
        $aging = $this->aging($clients, $filters);

        $horizon = fn (int $days): float => round($this->sumRub(
            $this->plannedQuery($clients, $filters)
                ->whereBetween('sch.due_date', [$today->toDateString(), $today->addDays($days)->toDateString()]),
            'sch.amount - sch.paid_amount - sch.prepaid_amount',
        ), 2);

        $noSchedule = $filters->includeNoSchedule
            ? $this->noScheduleTotal($clients, $filters)
            : 0.0;

        $balanceTotals = DB::table('contractor_balances')
            ->whereIn('user_id', (clone $clients))
            ->selectRaw('SUM(current_balance) as balance, SUM(overdue_debt) as overdue')
            ->first();

        $advances = (float) DB::table('payments')
            ->whereNull('deleted_at')
            ->whereIn('user_id', (clone $clients))
            ->where('direction', Payment::DIRECTION_IN)
            ->sum('unallocated_amount');

        return [
            'expected_period' => round($this->sumRub(
                $this->plannedQuery($clients, $filters)
                    ->whereBetween('sch.due_date', [$filters->dateFrom->toDateString(), $filters->dateTo->toDateString()]),
                'sch.amount - sch.paid_amount - sch.prepaid_amount',
            ), 2),
            'expected_7' => $horizon(7),
            'expected_14' => $horizon(14),
            'expected_30' => $horizon(30),
            'expected_month' => round($this->sumRub(
                $this->plannedQuery($clients, $filters)
                    ->whereBetween('sch.due_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()]),
                'sch.amount - sch.paid_amount - sch.prepaid_amount',
            ), 2),
            'overdue_amount' => $aging['total'],
            'overdue_count' => $aging['count'],
            'overdue_clients' => $aging['clients'],
            'no_schedule_amount' => round($noSchedule, 2),
            'debt_total' => round((float) ($balanceTotals->balance ?? 0), 2),
            'erp_overdue_total' => round((float) ($balanceTotals->overdue ?? 0), 2),
            'advances' => round($advances, 2),
        ];
    }

    /**
     * Клиенты с наибольшей просрочкой — блок «Кому звонить» на пульте.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function topDebtors(EloquentBuilder $clients, FinanceFilters $filters, int $limit = 10): array
    {
        $today = CarbonImmutable::today();

        $rows = $this->plannedQuery($clients, $filters)
            ->whereDate('sch.due_date', '<', $today->toDateString())
            ->get();

        $byClient = [];

        foreach ($rows as $row) {
            $id = (int) $row->user_id;
            $byClient[$id] ??= [
                'id' => $id,
                'name' => $row->client_erp_name ?: $row->client_name,
                'manager_name' => $row->manager_name,
                'url' => route('crm.clients.show', $id),
                'amount' => 0.0,
                'count' => 0,
                'max_days' => 0,
            ];

            $byClient[$id]['amount'] += $this->toRub($this->unpaidOf($row), $row->currency_code);
            $byClient[$id]['count']++;
            $byClient[$id]['max_days'] = max(
                $byClient[$id]['max_days'],
                (int) CarbonImmutable::parse($row->due_date)->diffInDays($today),
            );
        }

        usort($byClient, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return array_map(
            fn (array $client): array => [...$client, 'amount' => round($client['amount'], 2)],
            array_slice($byClient, 0, $limit),
        );
    }

    /**
     * Строка плана в виде, пригодном и для таблицы, и для выгрузки.
     *
     * @return array<string, mixed>
     */
    public function row(object $raw, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $unpaid = $this->unpaidOf($raw);
        $dueDate = $raw->due_date !== null ? CarbonImmutable::parse($raw->due_date) : null;
        $isOverdue = $dueDate !== null && $dueDate->lessThan($today);
        $key = ($dueDate !== null ? 'sch-' : 'shp-').$raw->id;

        return [
            // Ключ таблицы: строки графика и реализации без графика живут в одном
            // списке, и голый id столкнулся бы между двумя источниками. Дублируется
            // в `id`, потому что DataTable ключует строки именно по нему.
            'key' => $key,
            'id' => $key,
            'source' => $dueDate !== null ? 'schedule' : 'no_schedule',
            'due_date' => $dueDate?->toDateString(),
            'due_date_label' => $dueDate?->format('d.m.Y') ?? 'Срок не определён',
            'amount' => round((float) $raw->amount, 2),
            'paid_amount' => round((float) $raw->paid_amount, 2),
            'unpaid_amount' => $unpaid,
            'unpaid_rub' => round($this->toRub($unpaid, $raw->currency_code), 2),
            'unpaid_label' => $this->unpaidLabel($unpaid, $raw->currency_code),
            'currency_code' => $raw->currency_code ?: 'RUB',
            'is_overdue' => $isOverdue,
            'days_overdue' => $isOverdue ? (int) $dueDate->diffInDays($today) : 0,
            'days_left' => $dueDate !== null && ! $isOverdue ? (int) $today->diffInDays($dueDate) : null,
            'stage_name' => $raw->stage_name,
            'organization_name' => $raw->organization_name,
            'manager_name' => $raw->manager_name,
            'client' => [
                'id' => (int) $raw->user_id,
                'name' => $raw->client_erp_name ?: $raw->client_name,
                'url' => route('crm.clients.show', $raw->user_id),
            ],
            'shipment' => [
                'id' => (int) $raw->shipment_id,
                'number' => $raw->shipment_erp_number ?: $raw->shipment_number ?: ('#'.$raw->shipment_id),
                'date' => $raw->shipment_date !== null ? CarbonImmutable::parse($raw->shipment_date)->format('d.m.Y') : null,
                'invoice_number' => $raw->invoice_number_display,
                'total' => round((float) $raw->shipment_total, 2),
                'url' => route('crm.shipments.show', $raw->shipment_id),
            ],
        ];
    }

    /**
     * Подпись остатка. Суммы форматируются на сервере, как и даты.
     *
     * Валютный документ показывается в рублях с исходной суммой в скобках: в отчёте
     * складываются рубли, и менеджер должен видеть, откуда взялось рублёвое число.
     */
    private function unpaidLabel(float $unpaid, ?string $currencyCode): string
    {
        $rub = $this->money($this->toRub($unpaid, $currencyCode), 'RUB');

        if ($currencyCode === null || $currencyCode === '' || $currencyCode === 'RUB') {
            return $rub;
        }

        return $rub.' ('.$this->money($unpaid, $currencyCode).')';
    }

    private function money(float $amount, ?string $currencyCode): string
    {
        $symbol = match ($currencyCode) {
            'RUB', null => '₽',
            'KZT' => '₸',
            'BYN' => 'Br',
            default => $currencyCode,
        };

        return number_format($amount, 2, ',', ' ').' '.$symbol;
    }

    /**
     * Остаток к оплате по строке. Переплата остатка не создаёт — отсюда max(0, …).
     */
    public function unpaidOf(object $row): float
    {
        $prepaid = (float) ($row->prepaid_amount ?? 0);

        return round(max(0.0, (float) $row->amount - (float) $row->paid_amount - $prepaid), 2);
    }

    /**
     * Сумма в рублях. Неизвестный код валюты трактуем как рубли: потерять строку
     * из отчёта хуже, чем показать её без пересчёта.
     */
    public function toRub(float $amount, ?string $currencyCode): float
    {
        if ($currencyCode === null || $currencyCode === '' || $currencyCode === 'RUB') {
            return $amount;
        }

        $rate = $this->rates()[$currencyCode] ?? null;

        return $rate !== null && $rate > 0 ? round($amount * $rate, 2) : $amount;
    }

    /**
     * Порядок показа и погашения: сначала по плановой дате, при равных датах —
     * по номеру строки из 1С (тот же порядок, что у FIFO-раскладки).
     */
    public function applyDefaultOrder(QueryBuilder $query, string $dueColumn = 'sch.due_date'): QueryBuilder
    {
        return $query
            ->orderBy($dueColumn)
            ->orderByRaw('COALESCE(sch.line_number, 2147483647)')
            ->orderBy('sch.id');
    }

    /**
     * Фильтры, общие для обеих веток плана. Период применяется отдельно: у строк
     * без графика плановой даты нет, и `whereBetween` выкинул бы их целиком.
     */
    private function applyCommonFilters(QueryBuilder $query, FinanceFilters $filters, bool $withDueDate = true): QueryBuilder
    {
        if ($filters->clientIds !== []) {
            $query->whereIn('s.user_id', $filters->clientIds);
        }

        if ($filters->organizationIds !== []) {
            $query->whereIn('s.organization_id', $filters->organizationIds);
        }

        if ($withDueDate && $filters->onlyOverdue) {
            $query->whereDate('sch.due_date', '<', CarbonImmutable::today()->toDateString());
        }

        return $query;
    }

    /**
     * @return Collection<string, float>
     */
    private function sumByDay(QueryBuilder $query, string $dateColumn, string $unpaidExpression): Collection
    {
        $rows = (clone $query)
            ->reorder()
            // select(), а не selectRaw(): агрегату нужен СВОЙ список колонок.
            // selectRaw только добавляет к тем, что уже выбрал plannedQuery,
            // и MySQL с only_full_group_by отвергает такой запрос целиком
            // (SQLite его молча выполняет — на нём эта ошибка не ловится).
            ->select(DB::raw('DATE('.$dateColumn.') as day'))
            ->selectRaw('s.currency_code as currency_code')
            ->selectRaw('SUM('.$unpaidExpression.') as unpaid')
            ->groupBy(DB::raw('DATE('.$dateColumn.')'), 's.currency_code')
            ->get();

        $byDay = [];

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $byDay[$day] = ($byDay[$day] ?? 0.0) + $this->toRub((float) $row->unpaid, $row->currency_code);
        }

        return collect($byDay)->map(fn (float $amount): float => round($amount, 2));
    }

    /**
     * Сумма выражения по запросу, сведённая в рубли через группировку по валюте.
     */
    private function sumRub(QueryBuilder $query, string $expression): float
    {
        $rows = (clone $query)
            ->reorder()
            // Свой список колонок — см. комментарий в sumByDay().
            ->select(DB::raw('s.currency_code as currency_code'))
            ->selectRaw('SUM('.$expression.') as unpaid')
            ->groupBy('s.currency_code')
            ->get();

        $total = 0.0;

        foreach ($rows as $row) {
            $total += $this->toRub((float) $row->unpaid, $row->currency_code);
        }

        return $total;
    }

    /**
     * @param  Collection<string, mixed>  $values
     * @param  callable(mixed): float  $extract
     */
    private function sumRange(Collection $values, CarbonImmutable $from, CarbonImmutable $to, callable $extract): float
    {
        $total = 0.0;

        foreach ($values as $day => $value) {
            if ($day >= $from->toDateString() && $day <= $to->toDateString()) {
                $total += $extract($value);
            }
        }

        return $total;
    }

    private function agingKey(int $days): string
    {
        foreach (self::AGING_BUCKETS as $bucket) {
            if ($bucket['to'] === null || $days <= $bucket['to']) {
                return $bucket['key'];
            }
        }

        return self::AGING_BUCKETS[array_key_last(self::AGING_BUCKETS)]['key'];
    }

    private function bucketLabel(CarbonImmutable $from, CarbonImmutable $to, string $granularity): string
    {
        return match ($granularity) {
            'day' => $from->format('d.m.Y'),
            'month' => $this->monthLabel($from),
            default => $from->format('d.m').' — '.$to->format('d.m'),
        };
    }

    /**
     * «Август 2026». Carbon::translatedFormat зависит от локали приложения,
     * а месяцы нужны по-русски в любом окружении.
     */
    private function monthLabel(CarbonImmutable $month): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return $months[(int) $month->format('n')].' '.$month->format('Y');
    }

    /**
     * @return array<string, float>
     */
    private function rates(): array
    {
        return $this->rates ??= Currency::query()
            ->pluck('exchange_rate', 'code')
            ->map(fn ($rate): float => (float) $rate)
            ->all();
    }

    /**
     * Реализации скоупа, у которых вообще нет графика, — для сноски на странице
     * плана: без неё непонятно, почему сумма меньше долга по балансам.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleCount(EloquentBuilder $clients, FinanceFilters $filters): int
    {
        return (clone $this->noScheduleQuery($clients, $filters))->reorder()->count();
    }

    /**
     * Долг реализаций без графика, сведённый в рубли.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleTotal(EloquentBuilder $clients, FinanceFilters $filters): float
    {
        return round($this->sumRub(
            $this->noScheduleQuery($clients, $filters),
            's.total_amount - s.paid_amount',
        ), 2);
    }
}
