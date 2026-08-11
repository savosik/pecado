<?php

namespace App\Services\Crm\Finance;

use App\Models\SettlementEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Счётное ядро CRM на ленте движений регистра взаиморасчётов (v16.0.0).
 *
 * Включается флагом `settlements.ledger_enabled`. Отдаёт те же экраны, что и
 * [`PaymentForecastService`](PaymentForecastService.php), но берёт числа из
 * одной таблицы вместо графа документов.
 *
 * ## Что исчезло по сравнению со старой реализацией
 *
 * | Было | Стало |
 * |---|---|
 * | Отдельная ветка «долг без графика» | Обычная строка регистра — категории больше нет |
 * | `amount - paid_amount - prepaid_amount` | `amount - settled_amount`, и `settled_amount` приходит из 1С |
 * | Сведение валют по текущему курсу | `amount_rub`, зафиксированный учётом на дату операции |
 * | Джойн четырёх таблиц ради разрезов | Разрезы лежат в самой строке |
 *
 * ## Область: паритет, а не расширение
 *
 * Плановые строки берутся только по документам вида `shipment`. Регистр знает и
 * плановые платежи по заказам, но сегодняшний интерфейс сплошь «реализация»:
 * строка таблицы ведёт на карточку реализации, а подпись календаря начинается
 * со слова «Реализация». Показать там заказ значило бы дать неработающую ссылку.
 *
 * Это ровно тот состав, что показывает старая реализация, — переключение флага
 * не должно менять набор строк. Предоплаты по заказам добавляются вместе
 * с интерфейсом в `fin-08`.
 */
class LedgerPaymentForecastService implements PaymentForecast
{
    use FormatsForecastRows;

    /**
     * Плановые строки графика, по которым ещё ждём денег.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function plannedQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        $query = DB::table('settlement_entries as sch')
            ->join('users as u', 'u.id', '=', 'sch.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->leftJoin('organizations as org', 'org.id', '=', 'sch.organization_id')
            // Реализация нужна ради реквизитов и ссылки: строка регистра
            // самодостаточна, но карточка документа живёт в своей таблице.
            ->join('shipments as s', function ($join): void {
                $join->on('s.uuid', '=', 'sch.document_uuid')->whereNull('s.deleted_at');
            })
            ->where('sch.nature', SettlementEntry::NATURE_PLAN)
            ->where('sch.document_kind', 'shipment')
            ->whereIn('sch.user_id', (clone $clients))
            // Константа в SQL, а не биндингом: у выражения `amount - settled_amount`
            // нет аффинности типа, и SQLite сравнил бы число с привязанной строкой.
            ->whereRaw('sch.amount - sch.settled_amount > '.SettlementEntry::EPSILON)
            ->select([
                'sch.id',
                's.id as shipment_id',
                'sch.date as due_date',
                'sch.amount',
                'sch.settled_amount as paid_amount',
                // Регистр не делит погашение на «разнесено» и «зачтено авансом»:
                // 1С отдаёт одну закрытую часть. Колонка нужна ради единой формы
                // строки — её читает общий row().
                DB::raw('0 as prepaid_amount'),
                DB::raw($this->stageNameExpression().' as stage_name'),
                'sch.line_number',
                's.number as shipment_number',
                's.erp_number as shipment_erp_number',
                's.date as shipment_date',
                's.invoice_number_display',
                's.total_amount as shipment_total',
                'sch.currency_code',
                'sch.user_id',
                'u.name as client_name',
                'u.erp_name as client_erp_name',
                'u.personal_manager_id',
                'pm.name as manager_name',
                'org.name as organization_name',
            ]);

        return $this->applyCommonFilters($query, $filters);
    }

    public function overdueOnly(QueryBuilder $query, ?CarbonImmutable $today = null): QueryBuilder
    {
        return $query->whereDate('sch.date', '<', ($today ?? CarbonImmutable::today())->toDateString());
    }

    public function dueBetween(QueryBuilder $query, string $from, string $to): QueryBuilder
    {
        // DATE(), а не голая колонка: каст `date` сохраняет значение форматом
        // соединения, и в SQLite там «2026-08-11 00:00:00». Строка, попадающая
        // ровно на верхнюю границу периода, сравнением строк отсекалась бы —
        // причём молча и только на краю диапазона.
        return $query->whereBetween(DB::raw('DATE(sch.date)'), [$from, $to]);
    }

    /**
     * Категории «долг без графика» в регистре не существует: строка без плановой
     * даты — это обычное фактическое движение, оно уже посчитано в балансе.
     *
     * Метод сохранён ради контракта и отдаёт пустую выборку той же формы:
     * контроллер дописывает к ней `orderByDesc('s.date')`, и подменить её
     * заглушкой без таблицы значило бы получить синтаксическую ошибку.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        return DB::table('shipments as s')->whereRaw('1 = 0')->select(['s.id', 's.date']);
    }

    /**
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleCount(EloquentBuilder $clients, FinanceFilters $filters): int
    {
        return 0;
    }

    /**
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function noScheduleTotal(EloquentBuilder $clients, FinanceFilters $filters): float
    {
        return 0.0;
    }

    /**
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return Collection<string, float>
     */
    public function dailyPlan(EloquentBuilder $clients, FinanceFilters $filters): Collection
    {
        $query = $this->dueBetween(
            $this->plannedQuery($clients, $filters),
            $filters->dateFrom->toDateString(),
            $filters->dateTo->toDateString(),
        );

        return $this->sumByDay($query);
    }

    /**
     * Фактические поступления по дням.
     *
     * Знак уже применён к сумме на стороне 1С, поэтому возвраты вычитаются сами —
     * без `CASE` по направлению платежа. Рублёвый эквивалент берётся из строки:
     * он зафиксирован учётом на дату операции и не поплывёт от курса.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return Collection<string, array{amount: float, count: int}>
     */
    public function factsByDay(EloquentBuilder $clients, string $from, string $to): Collection
    {
        $rows = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('type', [SettlementEntry::TYPE_PAYMENT_IN, SettlementEntry::TYPE_PAYMENT_OUT])
            ->whereIn('user_id', (clone $clients))
            // DATE() по той же причине, что в dueBetween().
            ->whereBetween(DB::raw('DATE(date)'), [$from, $to])
            ->groupBy(DB::raw('DATE(date)'))
            ->select(DB::raw('DATE(date) as day'))
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as amount')
            ->selectRaw('COUNT(*) as documents')
            ->get();

        return collect($rows)->mapWithKeys(static fn (object $row): array => [
            (string) $row->day => [
                'amount' => round((float) $row->amount, 2),
                'count' => (int) $row->documents,
            ],
        ]);
    }

    /**
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function aging(EloquentBuilder $clients, FinanceFilters $filters): array
    {
        $today = CarbonImmutable::today();
        $rows = $this->overdueOnly($this->plannedQuery($clients, $filters), $today)->get();

        $totals = array_fill_keys(array_column(self::AGING_BUCKETS, 'key'), ['amount' => 0.0, 'count' => 0]);
        $total = 0.0;
        $clientIds = [];

        foreach ($rows as $row) {
            $unpaid = $this->toRub($this->unpaidOf($row), $row->currency_code);
            $key = $this->agingKey((int) CarbonImmutable::parse($row->due_date)->diffInDays($today));

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
     * Балансы по ленте движений, сгруппированные по партнёру.
     *
     * Нижний уровень — контрагент, как и раньше: 1С ведёт расчёты по ним, и
     * у партнёра их бывает несколько. Просрочка теперь выводится из непогашенных
     * плановых строк, а не приходит отдельным полем.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function balances(EloquentBuilder $clients): array
    {
        $facts = DB::table('settlement_entries as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
            ->where('e.nature', SettlementEntry::NATURE_FACT)
            ->whereIn('e.user_id', (clone $clients))
            ->groupBy('e.user_id', 'e.company_id', 'u.name', 'u.erp_name', 'pm.name', 'c.name', 'c.tax_id')
            ->select(['e.user_id', 'e.company_id', 'u.name as client_name', 'u.erp_name as client_erp_name'])
            ->selectRaw('pm.name as manager_name, c.name as company_name, c.tax_id as tax_id')
            ->selectRaw('SUM(COALESCE(e.amount_rub, e.amount)) as balance')
            ->selectRaw('MAX(e.erp_updated_at) as erp_updated_at')
            ->get();

        $overdue = $this->overdueByCompany($clients);

        $grouped = [];

        foreach ($facts as $row) {
            $userId = (int) $row->user_id;
            $companyOverdue = round((float) ($overdue[(int) $row->company_id] ?? 0.0), 2);

            $grouped[$userId] ??= [
                'id' => $userId,
                'client' => [
                    'id' => $userId,
                    'name' => $row->client_erp_name ?: $row->client_name,
                    'url' => route('crm.clients.show', $userId),
                ],
                'manager_name' => $row->manager_name,
                'current_balance' => 0.0,
                'overdue_debt' => 0.0,
                'erp_updated_at' => null,
                'contractors' => [],
            ];

            $grouped[$userId]['current_balance'] += (float) $row->balance;
            $grouped[$userId]['overdue_debt'] += $companyOverdue;
            $grouped[$userId]['erp_updated_at'] = max(
                $grouped[$userId]['erp_updated_at'],
                $row->erp_updated_at,
            );

            $grouped[$userId]['contractors'][] = [
                'id' => (int) $row->company_id,
                'company_name' => $row->company_name,
                'tax_id' => $row->tax_id,
                'current_balance' => round((float) $row->balance, 2),
                'overdue_debt' => $companyOverdue,
                'erp_updated_at' => $row->erp_updated_at !== null
                    ? CarbonImmutable::parse($row->erp_updated_at)->format('d.m.Y H:i')
                    : null,
            ];
        }

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
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function summary(EloquentBuilder $clients, FinanceFilters $filters): array
    {
        $today = CarbonImmutable::today();
        $aging = $this->aging($clients, $filters);

        $horizon = fn (int $days): float => round($this->sumRub($this->dueBetween(
            $this->plannedQuery($clients, $filters),
            $today->toDateString(),
            $today->addDays($days)->toDateString(),
        )), 2);

        $factTotals = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('user_id', (clone $clients))
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as balance')
            ->first();

        $balance = round((float) ($factTotals->balance ?? 0), 2);

        return [
            'expected_period' => round($this->sumRub($this->dueBetween(
                $this->plannedQuery($clients, $filters),
                $filters->dateFrom->toDateString(),
                $filters->dateTo->toDateString(),
            )), 2),
            'expected_7' => $horizon(7),
            'expected_14' => $horizon(14),
            'expected_30' => $horizon(30),
            'expected_month' => round($this->sumRub($this->dueBetween(
                $this->plannedQuery($clients, $filters),
                $today->startOfMonth()->toDateString(),
                $today->endOfMonth()->toDateString(),
            )), 2),
            'overdue_amount' => $aging['total'],
            'overdue_count' => $aging['count'],
            'overdue_clients' => $aging['clients'],
            // Категории больше нет — ключ сохранён, чтобы не менять форму ответа.
            'no_schedule_amount' => 0.0,
            'debt_total' => $balance,
            'erp_overdue_total' => $aging['total'],
            // Свободный аванс — это положительный фактический баланс и ничего больше.
            'advances' => round($this->advances($clients), 2),
            // Новое: фактическое сальдо отдельно от текущей задолженности. Раньше
            // эти два числа не различались, и именно отсюда росла путаница —
            // «сколько клиент должен» и «сколько просрочено» смешивались в одно.
            'balance_fact' => $balance,
        ];
    }

    /**
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function topDebtors(EloquentBuilder $clients, FinanceFilters $filters, int $limit = 10): array
    {
        $today = CarbonImmutable::today();
        $rows = $this->overdueOnly($this->plannedQuery($clients, $filters), $today)->get();

        $byClient = [];

        foreach ($rows as $row) {
            $id = (int) $row->user_id;
            $days = (int) CarbonImmutable::parse($row->due_date)->diffInDays($today);

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
            $byClient[$id]['max_days'] = max($byClient[$id]['max_days'], $days);
        }

        usort($byClient, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return array_map(
            static fn (array $client): array => [...$client, 'amount' => round($client['amount'], 2)],
            array_slice($byClient, 0, $limit),
        );
    }

    /**
     * Порядок показа: сначала по плановой дате, при равных — по номеру строки.
     */
    public function applyDefaultOrder(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->orderBy('sch.date')
            ->orderByRaw('COALESCE(sch.line_number, 2147483647)')
            ->orderBy('sch.id');
    }

    /**
     * Просрочка по контрагентам — для колонки в балансах.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<int, float>
     */
    private function overdueByCompany(EloquentBuilder $clients): array
    {
        return DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_PLAN)
            ->whereIn('user_id', (clone $clients))
            ->whereNotNull('company_id')
            ->whereDate('date', '<', CarbonImmutable::today()->toDateString())
            ->whereRaw('amount - settled_amount > '.SettlementEntry::EPSILON)
            ->groupBy('company_id')
            ->select('company_id')
            ->selectRaw('SUM(amount - settled_amount) as overdue')
            ->pluck('overdue', 'company_id')
            ->map(static fn ($value): float => (float) $value)
            ->all();
    }

    /**
     * Свободный аванс: сумма положительных балансов контрагентов.
     *
     * Считается по контрагентам, а не одним итогом: долг одного контрагента
     * не гасит переплату другого — взаимозачёт делает 1С, а не отчёт.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    private function advances(EloquentBuilder $clients): float
    {
        $balances = DB::table('settlement_entries')
            ->where('nature', SettlementEntry::NATURE_FACT)
            ->whereIn('user_id', (clone $clients))
            ->groupBy('company_id')
            ->select('company_id')
            ->selectRaw('SUM(COALESCE(amount_rub, amount)) as balance')
            ->pluck('balance');

        return (float) $balances->sum(static fn ($balance): float => max(0.0, (float) $balance));
    }

    /**
     * Реквизиты этапа лежат в JSON: шесть колонок ради подписи в календаре
     * не окупались. `json_extract` есть в обоих движках, но MySQL возвращает
     * значение в кавычках, и снимать их нужно только там.
     */
    private function stageNameExpression(): string
    {
        $extract = "json_extract(sch.meta, '$.stage_name')";

        return DB::connection()->getDriverName() === 'sqlite'
            ? $extract
            : 'JSON_UNQUOTE('.$extract.')';
    }

    /**
     * Фильтры, общие для всех выборок плана.
     */
    private function applyCommonFilters(QueryBuilder $query, FinanceFilters $filters): QueryBuilder
    {
        if ($filters->clientIds !== []) {
            $query->whereIn('sch.user_id', $filters->clientIds);
        }

        if ($filters->organizationIds !== []) {
            $query->whereIn('sch.organization_id', $filters->organizationIds);
        }

        if ($filters->onlyOverdue) {
            $this->overdueOnly($query);
        }

        return $query;
    }

    /**
     * @return Collection<string, float>
     */
    private function sumByDay(QueryBuilder $query): Collection
    {
        $rows = (clone $query)
            ->reorder()
            // select(), а не selectRaw(): агрегату нужен СВОЙ список колонок.
            // selectRaw только добавляет к тем, что выбрал plannedQuery, и MySQL
            // с only_full_group_by отвергает такой запрос целиком, а SQLite
            // молча выполняет — на нём эта ошибка не ловится.
            ->select('sch.date')
            ->selectRaw('sch.currency_code as currency_code')
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as unpaid')
            ->groupBy('sch.date', 'sch.currency_code')
            ->get();

        $byDay = [];

        foreach ($rows as $row) {
            $day = CarbonImmutable::parse($row->date)->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0.0) + $this->toRub((float) $row->unpaid, $row->currency_code);
        }

        return collect($byDay)->map(static fn (float $amount): float => round($amount, 2));
    }

    /**
     * Сумма непогашенного плана по запросу, сведённая в рубли.
     */
    private function sumRub(QueryBuilder $query): float
    {
        $rows = (clone $query)
            ->reorder()
            // Свой список колонок — см. комментарий в sumByDay().
            ->select(DB::raw('sch.currency_code as currency_code'))
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as unpaid')
            ->groupBy('sch.currency_code')
            ->get();

        $total = 0.0;

        foreach ($rows as $row) {
            $total += $this->toRub((float) $row->unpaid, $row->currency_code);
        }

        return $total;
    }
}
