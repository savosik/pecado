<?php

namespace App\Services\Crm\Finance;

use App\Models\SettlementEntry;
use App\Services\Payroll\Invoices\InvoiceNumberNormalizer;
use App\Support\Settlements\SettlementObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Юридический план поступлений: график платежей, присланный 1С, без единой
 * собственной оценки.
 *
 * Раздел отвечает финансисту, который верстает бюджет по обязательствам:
 * отгрузили — обязательство возникло, срок назначен в 1С, это и есть план.
 * Прежняя версия взвешивала тот же график на «платёжную дисциплину» партнёра
 * и достраивала регрессией то, чего в графике нет. Для бюджета такое число
 * бесполезно: перенести в него можно обязательство, а не матожидание.
 *
 * **Заказы в план не входят вовсе.** Заказ — намерение: клиент может его
 * отменить, и не случится ничего. Обязательство создаёт отгрузка, и вместе
 * с реализацией приходит её собственный график — тот, по которому и надо
 * ждать деньги. Пока отгрузки нет, ждать нечего; когда она есть, план заказа
 * дублирует план реализации: на 01.09.2026 из 3,1 млн ₽ по заказам 2,04 млн
 * (66 %) приходились на уже отгруженные, то есть считались дважды.
 *
 * Вдобавок 1С по заказам об оплате не отчитывается — регистр
 * «РасчетыСКлиентамиПоСрокам» их не ведёт (круг 12 сверки), `settled_amount`
 * у них ноль навсегда. Такую строку невозможно ни погасить, ни проверить.
 */
class PaymentPlanService
{
    use FormatsForecastRows;

    /** Заказ клиента: намерение, а не обязательство. В план не берётся. */
    public const KIND_ORDER = 'order';

    /** Потолок строк детализации дня: защита от дня-выброса. */
    public const DAY_LIMIT = 500;

    /**
     * С какой даты на сайте есть карточки реализаций.
     *
     * Регистр знает документы 2025 года, которых сайт никогда не видел.
     * Ненайденный документ тех лет — норма, а такой же за 2026-й — сигнал
     * рассинхрона, и путать эти два случая в подсказке нельзя.
     */
    public const DOCUMENTS_SINCE = '2026-01-19';

    public function __construct(
        private readonly PaymentForecast $forecast,
        private readonly InvoiceNumberNormalizer $numbers,
    ) {}

    /**
     * Итоги периода: сколько ждём по отгруженному.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function summary(EloquentBuilder $clients, FinanceFilters $filters): array
    {
        $rows = $this->totalsQuery($this->periodQuery($clients, $filters))->get();

        $total = 0.0;
        $lines = 0;
        $documents = 0;

        foreach ($rows as $row) {
            $total += $this->toRub((float) $row->unpaid, $row->currency_code);
            $lines += (int) $row->lines_count;
            $documents += (int) $row->documents;
        }

        $horizon = $this->horizon($clients, $filters);

        return [
            'total' => round($total, 2),
            'lines' => $lines,
            'documents' => $documents,
            'horizon' => $horizon,
            'horizon_label' => $horizon !== null ? CarbonImmutable::parse($horizon)->format('d.m.Y') : null,
            // Конец периода правее последней плановой строки: дальше не «ноль
            // поступлений», а отсутствие данных, и подписать это обязательно.
            'beyond_horizon' => $horizon !== null && $filters->dateTo->toDateString() > $horizon,
        ];
    }

    /**
     * От кого ждём денег в периоде: строка на партнёра.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function byPartner(EloquentBuilder $clients, FinanceFilters $filters): array
    {
        $rows = $this->periodQuery($clients, $filters)
            ->reorder()
            ->select([
                DB::raw('sch.user_id as user_id'),
                DB::raw('u.name as client_name'),
                DB::raw('u.erp_name as client_erp_name'),
                DB::raw('pm.name as manager_name'),
                DB::raw('sch.currency_code as currency_code'),
            ])
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as unpaid')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('COUNT(DISTINCT sch.document_uuid) as documents')
            ->groupBy(
                'sch.user_id',
                'u.name',
                'u.erp_name',
                'pm.name',
                'sch.currency_code',
            )
            ->get();

        $partners = [];

        foreach ($rows as $row) {
            $id = (int) $row->user_id;
            $partners[$id] ??= [
                'user_id' => $id,
                'title' => $row->client_erp_name ?: $row->client_name,
                'manager_name' => $row->manager_name,
                'url' => route('crm.clients.show', $id),
                'total' => 0.0,
                'lines' => 0,
                'documents' => 0,
            ];

            $partners[$id]['total'] += $this->toRub((float) $row->unpaid, $row->currency_code);
            $partners[$id]['lines'] += (int) $row->lines_count;
            $partners[$id]['documents'] += (int) $row->documents;
        }

        /** @var list<array<string, mixed>> $result */
        $result = array_values($partners);

        foreach ($result as $index => $partner) {
            $result[$index]['total'] = round((float) $partner['total'], 2);
        }

        usort($result, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $result;
    }

    /**
     * План и факт по дням: числа клеток календаря.
     *
     * Отдаёт три величины плана, а не одну. `plan` — остаток, то есть сколько
     * ещё должны; `scheduled` — сколько всего стояло в графике этого дня;
     * `settled` — сколько из этого 1С уже засчитала оплаченным. Для будущего
     * дня осмыслен только остаток, но на прошедшем дне без первых двух чисел
     * экран показывал бы хвост вместо картины: 27.08.2026 по графику стояло
     * 603 124 ₽, половину заплатили, и «ждём 300 тыс» читалось как весь день.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, array<string, float|int>>
     */
    public function days(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $days = [];

        // Закрытые строки тоже нужны: без них не сказать, сколько в этот день
        // назначили к оплате. Поэтому запрос идёт мимо planQuery() — та по
        // определению отдаёт только непогашенное.
        $plan = $this->scheduleQuery($clients, $filters)
            ->whereBetween(DB::raw('DATE(sch.date)'), [$from->toDateString(), $to->toDateString()])
            ->select([
                DB::raw('DATE(sch.date) as day'),
                DB::raw('sch.currency_code as currency_code'),
            ])
            ->selectRaw('SUM(sch.amount) as scheduled')
            ->selectRaw('SUM(sch.settled_amount) as settled')
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as unpaid')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('SUM(CASE WHEN sch.amount - sch.settled_amount > '.SettlementEntry::EPSILON.' THEN 1 ELSE 0 END) as open_lines')
            ->groupBy(DB::raw('DATE(sch.date)'), 'sch.currency_code')
            ->get();

        foreach ($plan as $row) {
            $day = (string) $row->day;
            $days[$day] ??= $this->emptyDay();

            $days[$day]['scheduled'] += $this->toRub((float) $row->scheduled, $row->currency_code);
            $days[$day]['settled'] += $this->toRub((float) $row->settled, $row->currency_code);
            // Остаток не может быть отрицательным: переплата по одной строке
            // не гасит долг по другой — это разные документы.
            $days[$day]['plan'] += max(0.0, $this->toRub((float) $row->unpaid, $row->currency_code));
            $days[$day]['scheduled_lines'] += (int) $row->lines_count;
            $days[$day]['plan_lines'] += (int) $row->open_lines;
        }

        $facts = $this->factQuery($clients, $filters)
            ->whereBetween(DB::raw('DATE(f.date)'), [$from->toDateString(), $to->toDateString()])
            ->select([
                DB::raw('DATE(f.date) as day'),
                DB::raw('f.currency_code as currency_code'),
            ])
            ->selectRaw('SUM(COALESCE(f.amount_rub, f.amount)) as amount')
            ->selectRaw('COUNT(*) as lines_count')
            ->groupBy(DB::raw('DATE(f.date)'), 'f.currency_code')
            ->get();

        foreach ($facts as $row) {
            $day = (string) $row->day;
            $days[$day] ??= $this->emptyDay();
            $days[$day]['fact'] += $this->toRub((float) $row->amount, $row->currency_code);
            $days[$day]['fact_count'] += (int) $row->lines_count;
        }

        foreach ($days as $day => $numbers) {
            $days[$day]['plan'] = round($numbers['plan'], 2);
            $days[$day]['scheduled'] = round($numbers['scheduled'], 2);
            $days[$day]['settled'] = round($numbers['settled'], 2);
            $days[$day]['fact'] = round($numbers['fact'], 2);
        }

        ksort($days);

        return $days;
    }

    /** @return array<string, float|int> */
    private function emptyDay(): array
    {
        return [
            'plan' => 0.0,
            'scheduled' => 0.0,
            'settled' => 0.0,
            'plan_lines' => 0,
            'scheduled_lines' => 0,
            'fact' => 0.0,
            'fact_count' => 0,
        ];
    }

    /**
     * Просрочка на начало периода: её срок уже прошёл, и в дни она не ставится.
     *
     * Счёт строгий — всё, что просрочено хоть на день, без льготного периода
     * и отсечки по сумме. Смягчённый счёт ведёт блокировки в разделе
     * «Дебиторка», и здесь он занизил бы картину, которую финансист сверяет
     * с разделом «Просрочка».
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return array<string, mixed>
     */
    public function overdueBefore(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $asOf,
    ): array {
        $rows = $this->forecast->overdueOnly(
            $this->forecast->plannedQuery($clients, $filters),
            $asOf,
        )
            ->reorder()
            ->select([
                DB::raw('sch.date as due_date'),
                DB::raw('sch.currency_code as currency_code'),
            ])
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as unpaid')
            ->selectRaw('COUNT(*) as lines_count')
            ->groupBy('sch.date', 'sch.currency_code')
            ->get();

        $buckets = [];

        foreach (self::AGING_BUCKETS as $bucket) {
            $buckets[$bucket['key']] = ['amount' => 0.0, 'lines' => 0];
        }

        $total = 0.0;
        $lines = 0;
        $oldest = 0;

        foreach ($rows as $row) {
            $days = (int) CarbonImmutable::parse($row->due_date)->diffInDays($asOf);
            $amount = $this->toRub((float) $row->unpaid, $row->currency_code);
            $key = $this->agingKey($days);

            $buckets[$key]['amount'] += $amount;
            $buckets[$key]['lines'] += (int) $row->lines_count;
            $total += $amount;
            $lines += (int) $row->lines_count;
            $oldest = max($oldest, $days);
        }

        return [
            'total' => round($total, 2),
            'lines' => $lines,
            'oldest_days' => $oldest,
            'buckets' => array_map(fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'amount' => round($buckets[$bucket['key']]['amount'], 2),
                'lines' => $buckets[$bucket['key']]['lines'],
            ], self::AGING_BUCKETS),
        ];
    }

    /**
     * Последняя плановая дата, которую прислала 1С.
     *
     * Считается по всему открытому графику, а не по выбранному периоду:
     * иначе горизонт «заканчивался» бы ровно там, куда смотрит пользователь.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    public function horizon(EloquentBuilder $clients, FinanceFilters $filters): ?string
    {
        $value = $this->planQuery($clients, $filters)
            ->reorder()
            ->max('sch.date');

        return $value !== null ? CarbonImmutable::parse($value)->toDateString() : null;
    }

    /**
     * Кто и по каким документам должен заплатить в этот день.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function dayPlan(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $date,
    ): array {
        $rows = $this->forecast->dueBetween(
            $this->planQuery($clients, $filters),
            $date->toDateString(),
            $date->toDateString(),
        )
            ->reorder()
            ->orderByDesc('sch.amount')
            ->limit(self::DAY_LIMIT)
            ->get();

        return $this->groupByPartner($rows, function (object $row): array {
            $unpaid = $this->toRub($this->unpaidOf($row), $row->currency_code);

            return [
                'kind_label' => self::DOCUMENT_KIND_LABELS[$row->document_kind ?? 'shipment'] ?? 'Документ',
                'number' => $row->shipment_erp_number ?: $row->shipment_number ?: '—',
                'date' => $row->shipment_date !== null
                    ? CarbonImmutable::parse($row->shipment_date)->format('d.m.Y')
                    : null,
                'amount' => $unpaid,
                'stage_name' => $row->stage_name,
                'url' => $row->shipment_id !== null
                    ? route('crm.shipments.show', $row->shipment_id)
                    : null,
            ];
        });
    }

    /**
     * Кто и за какие документы заплатил в этот день.
     *
     * Возвраты входят со своим знаком: деньги, вернувшиеся партнёру, в этот
     * день не пришли. Зачёты и корректировки долга сюда не попадают — они
     * гасят задолженность, но деньгами не являются.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     * @return list<array<string, mixed>>
     */
    public function dayFacts(
        EloquentBuilder $clients,
        FinanceFilters $filters,
        CarbonImmutable $date,
    ): array {
        $rows = $this->factQuery($clients, $filters)
            ->whereBetween(DB::raw('DATE(f.date)'), [$date->toDateString(), $date->toDateString()])
            ->select([
                DB::raw('f.user_id as user_id'),
                DB::raw('u.name as client_name'),
                DB::raw('u.erp_name as client_erp_name'),
                DB::raw('pm.name as manager_name'),
                DB::raw('f.amount as amount'),
                DB::raw('f.amount_rub as amount_rub'),
                DB::raw('f.currency_code as currency_code'),
                DB::raw('f.settlement_object_name as object_name'),
                DB::raw('f.document_number as document_number'),
                DB::raw('f.type as type'),
            ])
            ->orderByDesc('f.amount')
            ->limit(self::DAY_LIMIT)
            ->get();

        $documents = $this->resolveDocuments($rows);

        return $this->groupByPartner($rows, function (object $row) use ($documents): array {
            $object = SettlementObject::parse($row->object_name);
            $amount = $row->amount_rub !== null
                ? (float) $row->amount_rub
                : $this->toRub((float) $row->amount, $row->currency_code);

            $key = $this->numbers->fromObjectName($row->object_name)
                ?? $this->numbers->orderKeyFromObjectName($row->object_name);
            $match = $key !== null ? ($documents[$key] ?? null) : null;

            return [
                'kind_label' => $object['kind_label'],
                'number' => $object['number'] ?? ($row->document_number ?: '—'),
                'date' => $object['date'],
                'amount' => round($amount, 2),
                'stage_name' => null,
                // Текст объекта расчётов показывается всегда: сказать, за что
                // пришли деньги, важнее, чем дать ссылку. Ссылка появляется,
                // только когда документ найден по номеру — объект расчётов 1С
                // и документ это разные сущности с несовпадающими UUID.
                'url' => $match['url'] ?? null,
                'matched' => $match !== null,
                'unmatched_hint' => $match === null ? $this->unmatchedHint($object) : null,
                'is_return' => $row->type === SettlementEntry::TYPE_PAYMENT_OUT,
            ];
        });
    }

    /**
     * Почему документа нет на сайте.
     *
     * Документ 2025 года отсутствует законно — сайт начал получать реализации
     * 19.01.2026. Ненайденный документ этого года означает уже рассинхрон, и
     * подпись обязана их различать: иначе настоящая поломка обмена утонет
     * в историческом шуме.
     *
     * @param  array{kind: ?string, kind_label: string, number: ?string, date: ?string}  $object
     */
    private function unmatchedHint(array $object): string
    {
        if ($object['number'] === null) {
            return 'Платёж не отнесён к документу';
        }

        if ($object['date'] !== null
            && CarbonImmutable::createFromFormat('d.m.Y', $object['date'])->toDateString() < self::DOCUMENTS_SINCE) {
            return 'Документ до '.CarbonImmutable::parse(self::DOCUMENTS_SINCE)->format('d.m.Y')
                .' — карточки на сайте нет';
        }

        return 'Документ на сайте не найден';
    }

    /**
     * Свод строк в группы «партнёр → документы»: один платёж 1С разносит на
     * десяток реализаций, и плоский список дня нечитаем.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupByPartner(\Illuminate\Support\Collection $rows, callable $document): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $id = (int) $row->user_id;
            $groups[$id] ??= [
                'user_id' => $id,
                'title' => $row->client_erp_name ?: $row->client_name,
                'manager_name' => $row->manager_name,
                'url' => route('crm.clients.show', $id),
                'amount' => 0.0,
                'documents' => [],
            ];

            $line = $document($row);
            $groups[$id]['amount'] += $line['amount'];
            $groups[$id]['documents'][] = $line;
        }

        /** @var list<array<string, mixed>> $result */
        $result = array_values($groups);

        foreach ($result as $index => $group) {
            $result[$index]['amount'] = round((float) $group['amount'], 2);
        }

        usort($result, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $result;
    }

    /**
     * Документы, за которые пришли деньги: нормализованный номер → ссылка.
     *
     * Разбор имени и сравнение номеров идут в PHP, а не в SQL: `SUBSTRING_INDEX`
     * в SQLite не существует, и такой запрос падал бы только на бою, где тесты
     * его не ловят. Сравнение — через `InvoiceNumberNormalizer`: он снимает
     * ведущие нули, латинскую и кириллическую «А» и регистр, из-за которых
     * «A2УТ-000768» и «29УТ-768» иначе считались бы разными документами.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     * @return array<string, array{url: string}>
     */
    private function resolveDocuments(\Illuminate\Support\Collection $rows): array
    {
        $shipmentNumbers = [];
        $orderNumbers = [];

        foreach ($rows as $row) {
            $raw = SettlementObject::parse($row->object_name)['number'];

            if ($raw === null) {
                continue;
            }

            if ($this->numbers->fromObjectName($row->object_name) !== null) {
                $shipmentNumbers[$raw] = true;
            } elseif ($this->numbers->orderKeyFromObjectName($row->object_name) !== null) {
                $orderNumbers[$raw] = true;
            }
        }

        return $this->lookup(
            'shipments',
            array_keys($shipmentNumbers),
            static fn (int $id): string => route('crm.shipments.show', $id),
        ) + $this->lookup(
            'orders',
            array_keys($orderNumbers),
            static fn (int $id): string => route('crm.orders.show', $id),
        );
    }

    /**
     * Документы по сырым номерам, проиндексированные нормализованным ключом.
     *
     * Отбор идёт по обеим колонкам номера: у заказов на сайте своя нумерация
     * (`ORD-2026-…`), а номер 1С лежит в `erp_number`.
     *
     * @param  list<string>  $numbers
     * @return array<string, array{url: string}>
     */
    private function lookup(string $table, array $numbers, callable $url): array
    {
        if ($numbers === []) {
            return [];
        }

        $query = DB::table($table)
            ->where(static function ($query) use ($numbers): void {
                $query->whereIn('number', $numbers)->orWhereIn('erp_number', $numbers);
            })
            ->select(['id', 'number', 'erp_number']);

        if ($table === 'shipments') {
            $query->whereNull('deleted_at');
        }

        $found = [];

        foreach ($query->get() as $document) {
            foreach ([$document->erp_number, $document->number] as $candidate) {
                $key = $this->numbers->key($candidate);

                if ($key !== null) {
                    $found[$key] ??= ['url' => $url((int) $document->id)];
                }
            }
        }

        return $found;
    }

    /**
     * Плановые строки, годные для плана поступлений: всё, кроме заказов.
     *
     * Единственная точка, где ставится это условие, — иначе оно неминуемо
     * разъедется между итогом, календарём и деталями дня, а разошедшиеся
     * числа на одном экране читаются как ошибка расчёта.
     *
     * NULL-ветка выписана явно: голое `<> 'order'` в SQL молча выбрасывает
     * строки без вида документа — та же ловушка описана в `overdueOnly()`.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    private function planQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        return $this->forecast->plannedQuery($clients, $filters)
            ->where(static function (QueryBuilder $query): void {
                $query->whereNull('sch.document_kind')
                    ->orWhere('sch.document_kind', '<>', self::KIND_ORDER);
            });
    }

    /**
     * Весь график, включая закрытые строки: база чисел исполнения.
     *
     * Без джойнов на документы — здесь считаются только суммы, а реквизиты
     * реализации нужны построчному списку, не агрегату.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    private function scheduleQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        return DB::table('settlement_entries as sch')
            ->where('sch.nature', SettlementEntry::NATURE_PLAN)
            ->whereIn('sch.user_id', (clone $clients))
            ->where(static function (QueryBuilder $query): void {
                $query->whereNull('sch.document_kind')
                    ->orWhere('sch.document_kind', '<>', self::KIND_ORDER);
            })
            ->when(
                $filters->clientIds !== [],
                fn (QueryBuilder $query) => $query->whereIn('sch.user_id', $filters->clientIds),
            )
            ->when(
                $filters->organizationIds !== [],
                fn (QueryBuilder $query) => $query->whereIn('sch.organization_id', $filters->organizationIds),
            );
    }

    /**
     * Плановые строки, чей срок попадает в выбранный период.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    private function periodQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        return $this->forecast->dueBetween(
            $this->planQuery($clients, $filters),
            $filters->dateFrom->toDateString(),
            $filters->dateTo->toDateString(),
        );
    }

    /** Суммы по валюте — база всех итогов раздела. */
    private function totalsQuery(QueryBuilder $query): QueryBuilder
    {
        return (clone $query)
            ->reorder()
            // Свой список колонок вместо унаследованного от plannedQuery:
            // с ним агрегат уронил бы only_full_group_by на MySQL, а SQLite
            // в тестах проглотил бы это молча.
            ->select([DB::raw('sch.currency_code as currency_code')])
            ->selectRaw('SUM(sch.amount - sch.settled_amount) as unpaid')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('COUNT(DISTINCT sch.document_uuid) as documents')
            ->groupBy('sch.currency_code');
    }

    /**
     * Фактические деньги: приход и возврат. Зачёты и корректировки долга
     * закрывают график, но деньгами не являются и в поток не входят.
     *
     * @param  EloquentBuilder<\App\Models\User>  $clients
     */
    private function factQuery(EloquentBuilder $clients, FinanceFilters $filters): QueryBuilder
    {
        return DB::table('settlement_entries as f')
            ->join('users as u', 'u.id', '=', 'f.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'u.personal_manager_id')
            ->where('f.nature', SettlementEntry::NATURE_FACT)
            ->whereIn('f.type', [SettlementEntry::TYPE_PAYMENT_IN, SettlementEntry::TYPE_PAYMENT_OUT])
            ->whereIn('f.user_id', (clone $clients))
            ->when(
                $filters->clientIds !== [],
                fn (QueryBuilder $query) => $query->whereIn('f.user_id', $filters->clientIds),
            )
            ->when(
                $filters->organizationIds !== [],
                fn (QueryBuilder $query) => $query->whereIn('f.organization_id', $filters->organizationIds),
            );
    }
}
