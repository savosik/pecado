<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Enums\OrderStatus;
use App\Enums\PrintedDocumentType;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CrmEntityResolver;
use App\Services\Crm\Finance\FinanceFilters;
use App\Services\Crm\Finance\PaymentForecast;
use App\Services\SimpleXlsxExporter;
use App\Support\Crm\CrmEntityMap;
use App\Support\Payments\PaymentSchedulePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Заказы и реализации внутри CRM: списки и карточки.
 *
 * Раньше из ленты и из списка документов ссылка вела в админку — куда роли
 * `sales-head` и `sales-manager-crm` намеренно не пускают. Менеджер видел
 * ссылку и упирался в 403, а РОП не мог посмотреть состав заказа вообще.
 *
 * Отдельного права нет: документ партнёра — часть его карточки, и «вижу партнёра,
 * но не вижу его заказы» — состояние, которого быть не должно. Доступ решает
 * тот же скоуп, что и везде, через CrmEntityResolver: чужой документ даёт 404.
 *
 * Читаем только — заказы и реализации принадлежат 1С, редактирование живёт
 * в админке и там же остаётся.
 */
class DocumentController extends CrmController
{
    /**
     * Поля, по которым разрешена сортировка списков.
     *
     * Белый список, а не проверка «есть такая колонка»: значение уходит
     * в orderBy как есть.
     *
     * @var list<string>
     */
    private const SORTS = ['id', 'number', 'erp_number', 'total_amount', 'created_at', 'erp_created_at', 'date', 'amount'];

    /**
     * Направления платежа для фильтра.
     *
     * @var list<array{value: string, label: string, color: string}>
     */
    private const PAYMENT_DIRECTIONS = [
        ['value' => 'in', 'label' => 'Поступление', 'color' => 'green'],
        ['value' => 'out', 'label' => 'Возврат партнёру', 'color' => 'red'],
    ];

    /**
     * Статусы реализаций: у них нет enum-а, 1С шлёт голые строки.
     *
     * @var list<array{value: string, label: string, color: string}>
     */
    private const SHIPMENT_STATUSES = [
        ['value' => 'new', 'label' => 'Новая', 'color' => 'blue'],
        ['value' => 'in_progress', 'label' => 'В обработке', 'color' => 'orange'],
        ['value' => 'completed', 'label' => 'Выполнена', 'color' => 'green'],
        ['value' => 'cancelled', 'label' => 'Отменена', 'color' => 'gray'],
    ];

    /**
     * Потолок выгрузки. Экран режется страницами, файл — этим числом: журнал
     * без фильтров у РОПа — это десятки тысяч документов, и вся эта таблица
     * собирается в памяти PhpSpreadsheet до отдачи.
     */
    private const EXPORT_LIMIT = 10000;

    private const EXPORT_CHUNK = 500;

    /**
     * Потолок строк просрочки в календаре: список под сеткой, а не отчёт —
     * полная просрочка живёт в /crm/finance/overdue.
     */
    private const CALENDAR_OVERDUE_LIMIT = 200;

    public function __construct(private readonly CrmEntityResolver $resolver) {}

    /**
     * Список заказов партнёров актора.
     *
     * Скоуп тот же, что у списка партнёров: менеджер видит документы только своих
     * партнёров, РОП — всего отдела. Реализовано подзапросом по user_id, а не
     * фильтром на фронте — иначе первый же `per_page=100` показал бы чужое.
     */
    public function orders(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        $search = $this->search($request);

        $query = $this->ordersQuery($request, $clients, $search)
            ->with([
                'user:id,name,erp_name,email,personal_manager_id',
                'user.personalManager:id,name',
                'company:id,name',
                'organization:id,name,is_stub',
                'warehouse:id,name',
            ])
            ->withCount('items');

        [$sortBy, $sortOrder] = $this->sort($request);
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $orders = $query->orderBy($sortBy, $sortOrder)->paginate($perPage)->withQueryString();

        $orders->getCollection()->transform(fn (Order $order): array => [
            'id' => (int) $order->getKey(),
            'number' => $order->number,
            'erp_number' => $order->erp_number,
            'status_label' => $order->status->label(),
            'status_color' => $order->status->color(),
            'date_label' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y H:i'),
            'total_label' => $this->money((float) $order->total_amount, $order->currency_code),
            'items_count' => (int) ($order->items_count ?? 0),
            'client' => $order->user === null ? null : [
                'id' => (int) $order->user->getKey(),
                'name' => $order->user->display_name,
                // Менеджер подписью под именем — тот же приём, что в журнале
                // платежей: РОП читает журнал отдела построчно.
                'manager_name' => $order->user->personalManager?->getAttribute('name'),
                'url' => route('crm.clients.show', $order->user->getKey()),
            ],
            'organization' => $order->organization === null ? null : [
                'name' => $order->organization->getAttribute('name'),
                'is_stub' => (bool) $order->organization->getAttribute('is_stub'),
            ],
            'warehouse' => $order->warehouse?->getAttribute('name'),
            'url' => route('crm.orders.show', $order->getKey()),
        ]);

        return Inertia::render('Crm/Pages/Documents/Orders', array_merge(
            [
                'orders' => $orders,
                'totals' => $this->documentTotals(
                    $this->ordersQuery($request, $clients, $search),
                    'total_amount',
                ),
            ],
            $this->listOptions($request, 'orders', $clients, $sortBy, $sortOrder, $perPage, $search),
            [
                'statuses' => array_map(
                    fn (OrderStatus $case): array => [
                        'value' => $case->value,
                        'label' => $case->label(),
                        'color' => $case->color(),
                    ],
                    OrderStatus::cases(),
                ),
            ],
        ));
    }

    /**
     * Список реализаций партнёров актора.
     */
    public function shipments(Request $request, PaymentForecast $forecast): Response
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        $search = $this->search($request);

        $query = $this->shipmentsQuery($request, $clients, $search)
            ->with([
                'user:id,name,erp_name,email,personal_manager_id',
                'user.personalManager:id,name',
                'company:id,name',
                'organization:id,name,is_stub',
                'warehouse:id,name',
            ])
            ->withCount('items');

        [$sortBy, $sortOrder] = $this->sort($request);
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $shipments = $query->orderBy($sortBy, $sortOrder)->paginate($perPage)->withQueryString();

        $shipments->getCollection()->transform(fn (Shipment $shipment): array => [
            'id' => (int) $shipment->getKey(),
            'number' => $shipment->number,
            'erp_number' => $shipment->erp_number,
            'status_label' => $shipment->status_label,
            'status_color' => $shipment->status === 'completed' ? 'green' : 'blue',
            'date_label' => ($shipment->erp_created_at ?? $shipment->date)?->format('d.m.Y H:i'),
            'total_label' => $this->money((float) $shipment->total_amount, $shipment->currency_code),
            'items_count' => (int) ($shipment->items_count ?? 0),
            'client' => $shipment->user === null ? null : [
                'id' => (int) $shipment->user->getKey(),
                'name' => $shipment->user->display_name,
                'manager_name' => $shipment->user->personalManager?->getAttribute('name'),
                'url' => route('crm.clients.show', $shipment->user->getKey()),
            ],
            'organization' => $shipment->organization === null ? null : [
                'name' => $shipment->organization->getAttribute('name'),
                'is_stub' => (bool) $shipment->organization->getAttribute('is_stub'),
            ],
            'warehouse' => $shipment->warehouse?->getAttribute('name'),
            'url' => route('crm.shipments.show', $shipment->getKey()),
        ]);

        return Inertia::render('Crm/Pages/Documents/Shipments', array_merge(
            [
                'shipments' => $shipments,
                'totals' => $this->documentTotals(
                    $this->shipmentsQuery($request, $clients, $search),
                    'total_amount',
                ),
                'schedule' => $this->shipmentScheduleTotals(
                    $this->shipmentsQuery($request, $clients, $search),
                    $forecast,
                ),
            ],
            $this->listOptions($request, 'shipments', $clients, $sortBy, $sortOrder, $perPage, $search),
            ['statuses' => self::SHIPMENT_STATUSES],
        ));
    }

    /**
     * Журнал платежей. GET /crm/payments
     *
     * Реквизиты и разнесение ведёт 1С — здесь только чтение, как и в остальных
     * журналах. Права отдельного нет по той же причине: оплаты партнёра — часть
     * его карточки, и «вижу партнёра, но не вижу, платил ли он» — состояние,
     * которого быть не должно.
     */
    public function payments(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        $search = $this->search($request);

        $query = $this->paymentsQuery($request, $clients, $search)
            ->with([
                'user:id,name,erp_name,email,personal_manager_id',
                'user.personalManager:id,name',
                'company:id,name',
                'organization:id,name,is_stub',
            ]);

        [$sortBy, $sortOrder] = $this->sort($request);
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $payments = $query->orderBy($sortBy, $sortOrder)->orderBy('id', 'desc')
            ->paginate($perPage)->withQueryString();

        $payments->getCollection()->transform(fn (Payment $payment): array => $this->paymentRow($payment));

        $options = $this->listOptions($request, 'payments', $clients, $sortBy, $sortOrder, $perPage, $search);

        // Свои фильтры платежей возвращаются экрану наравне с общими: без них
        // галочки в «Направлении» и «Разнесении» не отрисовывались, а любое
        // следующее применение фильтра теряло их — снимок отбора собирается
        // на клиенте именно из этого массива.
        $options['filters']['directions'] = array_values(array_intersect(
            $this->values($request, 'directions', 'direction'),
            array_column(self::PAYMENT_DIRECTIONS, 'value'),
        ));

        return Inertia::render('Crm/Pages/Finance/Payments', array_merge(
            ['payments' => $payments],
            $options,
            [
                'directions' => self::PAYMENT_DIRECTIONS,
                'totals' => $this->paymentTotals($request, $clients, $search),
            ],
        ));
    }

    /**
     * Оплата реализаций отбора — числами счётного ядра раздела «Финансы».
     *
     * ## Почему не своим запросом
     *
     * Первый заход считал по `shipments.paid_amount` — проекции расшифровки
     * платежей, которой 1С больше не присылает: «не оплачено» вышло 44,4 млн ₽
     * при 11,5 млн ₽ реального долга. Второй — по `shipment_payment_schedules`,
     * и это тоже мимо: с v16.0.0 график приезжает событием `payment_schedule.updated`
     * в плановые движения регистра, а старая таблица не пополняется с 12.08.2026.
     *
     * Правильный ответ у того же ядра, на котором стоят пульт и просрочка
     * (`PaymentForecast`): какое из двух включено, решает флаг `settlements.ledger_enabled`,
     * и журнал знать об этом не должен — иначе он покажет одно число, а пульт другое.
     *
     * @param  Builder<Shipment>  $shipments  отбор журнала без пагинации
     * @return array{buckets: list<array<string, mixed>>, without_plan: int}
     */
    private function shipmentScheduleTotals(Builder $shipments, PaymentForecast $forecast): array
    {
        $totals = $forecast->shipmentPaymentTotals($shipments);

        return [
            'buckets' => array_map(fn (array $bucket): array => [
                'currency' => $bucket['currency'],
                'docs' => $bucket['docs'],
                'paid_label' => $this->money($bucket['paid'], $bucket['currency']),
                'unpaid_label' => $this->money($bucket['unpaid'], $bucket['currency']),
            ], $totals['buckets']),
            'without_plan' => $totals['without_plan'],
        ];
    }

    /**
     * Итоги журнала документов по текущему отбору.
     *
     * Считается по тому же запросу, что и список, но без пагинации: «на сколько
     * отгрузили за август» — вопрос, ради которого фильтр и открывают, а сумма
     * показанной страницы на него не отвечает.
     *
     * Разрез по валюте, а не сведение в рубли: курса на дату документа сайт
     * не знает, а складывать рубли с тенге в итоге, который читают как деньги,
     * нельзя.
     *
     * ## Почему здесь нет «оплачено» и «остатка»
     *
     * Соблазн вычесть `paid_amount` из суммы велик, а результат — ложь: колонка
     * заполнялась расшифровкой платежей, которой 1С больше не присылает
     * (`allocations[]` удалены в v16.0.0 и вмещали меньше трети денег), и долг
     * 1С гасит ещё авансами по заказам, взаимозачётами и корректировками.
     * Замер боевой базы 25.08.2026: такой «остаток» дал 44,4 млн ₽ против
     * 11,5 млн ₽ реального долга по `contractor_balances`. Деньги живут
     * в регистре взаиморасчётов — в «Балансах партнёров» и акте сверки.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array{count: int, buckets: list<array{currency: string, count: int, amount_label: string}>}
     */
    private function documentTotals(Builder $query, string $amountColumn): array
    {
        $rows = $query->toBase()
            ->select([
                'currency_code',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM('.$amountColumn.') as total'),
            ])
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        return [
            'count' => (int) $rows->sum('cnt'),
            'buckets' => $rows->map(fn (object $row): array => [
                'currency' => (string) ($row->currency_code ?: 'RUB'),
                'count' => (int) $row->cnt,
                'amount_label' => $this->money((float) $row->total, $row->currency_code),
            ])->all(),
        ];
    }

    /**
     * Итоги по текущему отбору: сколько платежей и на какую сумму.
     *
     * Считается по тому же запросу, что и список, но без пагинации: менеджеру
     * нужна сумма всего отбора, а не показанной страницы. Разрез по направлению
     * обязателен — складывать поступления с возвратами бессмысленно, а разрез
     * по валюте честнее конвертации: курс на дату платежа сайту неизвестен.
     *
     * @param  Builder<User>  $clients
     * @return array{count: int, buckets: list<array{currency: string, direction: string, direction_label: string, count: int, amount_label: string}>}
     */
    private function paymentTotals(Request $request, Builder $clients, ?string $search): array
    {
        $rows = $this->paymentsQuery($request, $clients, $search)
            ->toBase()
            ->selectRaw('currency_code, direction, COUNT(*) as cnt, SUM(amount) as total')
            ->groupBy('currency_code', 'direction')
            ->orderBy('currency_code')
            ->orderBy('direction')
            ->get();

        $labels = array_column(self::PAYMENT_DIRECTIONS, 'label', 'value');

        return [
            'count' => (int) $rows->sum('cnt'),
            'buckets' => $rows->map(fn (object $row): array => [
                'currency' => (string) ($row->currency_code ?: 'RUB'),
                'direction' => (string) $row->direction,
                'direction_label' => $labels[$row->direction] ?? (string) $row->direction,
                'count' => (int) $row->cnt,
                'amount_label' => $this->money((float) $row->total, $row->currency_code),
            ])->all(),
        ];
    }

    /**
     * Календарь поступления денег. GET /crm/payments/calendar?month=YYYY-MM
     *
     * План и факт вместе: план — остатки по графику оплаты реализаций
     * («Правила оплаты» 1С), факт — проведённые платежи по их бизнес-дате `date`.
     * Разрез по менеджерам работает через тот же скоуп партнёров, что и журналы,
     * поэтому отдельного фильтра здесь нет.
     *
     * Расчёт берётся из реализации PaymentForecast — той же, на которой стоит раздел
     * «Финансы»: календарь и пульт обязаны показывать одно число, а два
     * независимых запроса неизбежно разъедутся. Оттуда же приходит сведение
     * валют в рубли — без него месяц с валютным документом складывал бы Br с ₽.
     */
    public function paymentsCalendar(Request $request, PaymentForecast $forecast): Response
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);

        $month = $this->resolveMonth($request->input('month'));
        $monthStart = $month->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();
        $today = Carbon::today();
        $todayImmutable = CarbonImmutable::today();

        // Календарю нужен только период: отбор по менеджерам уже сведён в скоуп партнёров.
        $filters = new FinanceFilters(
            dateFrom: CarbonImmutable::parse($monthStart),
            dateTo: CarbonImmutable::parse($monthEnd),
        );

        $planned = $forecast->applyDefaultOrder(
            $forecast->dueBetween($forecast->plannedQuery($clients, $filters), $monthStart, $monthEnd)
        )->get();

        // Просрочка не привязана к показываемому месяцу: менеджеру она нужна
        // всегда, в каком бы месяце он ни находился.
        $overdue = $forecast->applyDefaultOrder(
            $forecast->overdueOnly($forecast->plannedQuery($clients, $filters), $todayImmutable)
        )->limit(self::CALENDAR_OVERDUE_LIMIT)->get();

        $facts = $forecast->factsByDay($clients, $monthStart, $monthEnd);
        $entries = $planned->map(fn (object $row): array => $forecast->row($row, $todayImmutable));
        $overdueEntries = $overdue->map(fn (object $row): array => $forecast->row($row, $todayImmutable));

        return Inertia::render('Crm/Pages/Finance/PaymentCalendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->monthLabel($month),
            'prevMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            'today' => $today->toDateString(),
            'entries' => $entries->all(),
            'overdueEntries' => $overdueEntries->all(),
            'facts' => $facts,
            'summary' => [
                'plan_month' => round($entries->sum(fn (array $entry): float => $entry['unpaid_rub']), 2),
                'fact_month' => round($facts->sum(fn (array $day): float => $day['amount']), 2),
                'overdue_amount' => round($overdueEntries->sum(fn (array $entry): float => $entry['unpaid_rub']), 2),
                'overdue_count' => $overdueEntries->count(),
            ],
            'managers' => $this->seesDepartment($request) ? $this->managerOptions() : [],
            'seesAll' => $this->seesDepartment($request),
            'filters' => [
                // Разрез отдаётся экрану, хотя фильтрует уже `visibleClients`:
                // без него календарь показывал сумму по своим партнёрам и никак
                // об этом не сообщал — расфокус нечем было ни увидеть, ни снять.
                'scope' => CrmScope::fromRequest($request, $this->crmActor($request))->value,
                'manager_ids' => $this->seesDepartment($request) ? $this->ids($request, 'manager_ids') : [],
            ],
        ]);
    }

    /**
     * Месяц из строки YYYY-MM. Мусор в параметре — текущий месяц, а не 500.
     */
    private function resolveMonth(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
            } catch (\Throwable) {
                // Падаем в текущий месяц ниже.
            }
        }

        return Carbon::today()->startOfMonth();
    }

    /**
     * «Август 2026». Carbon::translatedFormat зависит от локали приложения,
     * а месяцы нужны по-русски в любом окружении.
     */
    private function monthLabel(Carbon $month): string
    {
        $names = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return $names[$month->month].' '.$month->year;
    }

    /**
     * Карточка платежа. GET /crm/payments/{payment}
     */
    public function payment(Request $request, int $payment): Response
    {
        $actor = $this->crmActor($request);

        /** @var Payment $model */
        $model = $this->resolver->resolveForActor($actor, CrmEntityMap::PAYMENT, $payment);

        $model->load([
            'user:id,name,erp_name,email,phone,personal_manager_id',
            'company:id,name,tax_id',
            'organization:id,name,is_stub',
        ]);

        $currency = $model->currency_code;

        // Расшифровки в карточке больше нет: 1С не присылает её с v16.0.0,
        // и «что закрыл платёж» видно в акте сверки и движениях регистра.

        return Inertia::render('Crm/Pages/Documents/Show', [
            'document' => [
                'type' => CrmEntityMap::PAYMENT,
                'id' => (int) $model->getKey(),
                'title' => 'Платёж №'.($model->number ?: $model->getKey()),
                'number' => $model->number,
                'erp_number' => null,
                'status_label' => $model->direction_label,
                'status_color' => $model->direction === Payment::DIRECTION_OUT ? 'red' : 'green',
                'date_label' => $model->date?->format('d.m.Y H:i'),
                'created_at_label' => $model->created_at?->format('d.m.Y H:i'),
                'total_label' => $this->money((float) $model->amount, $currency),
                'comment' => $model->comment,
                'manager_comment' => null,
                'delivery_address' => null,
                'organization' => $this->organization($model),
                'warehouse' => null,
                'company' => $model->company?->getAttribute('name'),
                'admin_url' => $actor->hasAdminAccess() ? route('admin.payments.show', $model->getKey()) : null,
                'items' => [],
                // Реквизиты платёжного поручения: у заказа и реализации такого
                // блока нет, поэтому проп опциональный и карточка его просто
                // не рисует для других типов.
                'details' => array_values(array_filter([
                    ['label' => 'Тип документа', 'value' => $model->document_type],
                    ['label' => 'Операция', 'value' => $model->operation_name],
                    ['label' => 'Номер по банку', 'value' => $model->bank_number],
                    ['label' => 'Дата по банку', 'value' => $model->bank_date?->format('d.m.Y')],
                    ['label' => 'Проведено банком', 'value' => $model->bank_confirmed ? 'Да' : 'Нет'],
                    ['label' => 'Счёт организации', 'value' => $model->organization_account],
                    ['label' => 'Банк организации', 'value' => $model->organization_bank_name],
                    ['label' => 'Счёт плательщика', 'value' => $model->payer_account],
                    ['label' => 'Банк плательщика', 'value' => $model->payer_bank_name],
                    ['label' => 'УИП', 'value' => $model->uip],
                    ['label' => 'Назначение платежа', 'value' => $model->purpose],
                ], fn (array $detail): bool => $detail['value'] !== null && $detail['value'] !== '')),
                'summary' => [
                    ['label' => 'Сумма платежа', 'value' => $this->money((float) $model->amount, $currency), 'tone' => 'neutral'],
                ],
                'related' => [],
            ],
            'client' => $this->clientPayload($model->user),
        ]);
    }

    /**
     * XLSX-выгрузка отобранных платежей. GET /crm/payments/export
     */
    public function paymentsExport(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        $organizationsEnabled = (bool) config('erp.organizations.enabled');

        [$sortBy, $sortOrder] = $this->sort($request);

        [$rows] = $this->collectExportRows(
            $this->paymentsQuery($request, $clients, $this->search($request)),
            $sortBy,
            $sortOrder,
            fn (\Illuminate\Database\Eloquent\Model $payment): array => $this->paymentExportRow($payment, $organizationsEnabled),
            [
                'user:id,name,erp_name,email,personal_manager_id',
                'user.personalManager:id,name',
                'company:id,name,tax_id',
                'organization:id,name,is_stub',
            ],
        );

        return $exporter->streamSheets('crm-payments-'.now()->format('Y-m-d-His'), [[
            'title' => 'Платежи',
            'headers' => array_values(array_filter([
                'Номер',
                'Дата',
                'Направление',
                'Партнёр',
                'Email',
                'Менеджер',
                'Контрагент',
                'ИНН',
                $organizationsEnabled ? 'Организация' : null,
                'Номер по банку',
                'Проведён банком',
                'Сумма',
                'Валюта',
            ])),
            'rows' => $rows,
        ]]);
    }

    /**
     * XLSX-выгрузка отобранных заказов. GET /crm/orders/export
     */
    public function ordersExport(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        $productIds = $this->ids($request, 'product_ids');

        return $this->exportDocuments(
            $request,
            $this->ordersQuery($request, $clients, $this->search($request)),
            $exporter,
            'crm-orders-'.now()->format('Y-m-d-His'),
            'Заказы',
            fn (array $ids): array => $this->orderItemRows($ids, $productIds),
        );
    }

    /**
     * XLSX-выгрузка отобранных реализаций. GET /crm/shipments/export
     */
    public function shipmentsExport(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        $productIds = $this->ids($request, 'product_ids');

        return $this->exportDocuments(
            $request,
            $this->shipmentsQuery($request, $clients, $this->search($request)),
            $exporter,
            'crm-shipments-'.now()->format('Y-m-d-His'),
            'Реализации',
            fn (array $ids): array => $this->shipmentItemRows($ids, $productIds),
        );
    }

    /**
     * Журнал печатных форм документов (v16.1.0).
     *
     * Менеджеру раздел нужен раньше клиента: пока идёт первичная выгрузка, именно
     * здесь видно, что 1С прислала на самом деле. Поэтому в отличие от кабинета
     * тут показываются и документы с проблемным файлом, и документы без контрагента —
     * это и есть диагностика обмена.
     */
    public function printedDocuments(Request $request): Response
    {
        abort_unless((bool) config('documents.crm_enabled'), 404);

        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        $search = $this->search($request);

        $query = $this->printedDocumentsQuery($request, $clients, $search)
            ->with([
                'user:id,name,erp_name,email',
                'company:id,name',
                'organization:id,name,is_stub',
                'order:id,number,erp_number',
                'shipment:id,number,erp_number',
            ]);

        [$sortBy, $sortOrder] = $this->printedDocumentsSort($request);
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $documents = $query->orderBy($sortBy, $sortOrder)->paginate($perPage)->withQueryString();

        $documents->getCollection()->transform(fn (PrintedDocument $document): array => [
            'id' => (int) $document->getKey(),
            'title' => $document->display_title,
            'type' => $document->type->value,
            'type_label' => $document->type_label,
            'type_color' => $document->type->color(),
            'number' => $document->number,
            'date_label' => $document->date?->format('d.m.Y'),
            'period_label' => $document->period_label,
            'format_label' => $document->format->label(),
            'file_status' => $document->file_status,
            'file_status_label' => PrintedDocument::FILE_STATUS_LABELS[$document->file_status] ?? $document->file_status,
            'size_label' => $document->size_bytes === null
                ? null
                : round($document->size_bytes / 1024 / 1024, 2).' МБ',
            'client' => $document->user === null ? null : [
                'id' => (int) $document->user->getKey(),
                'name' => $document->user->display_name,
                'url' => route('crm.clients.show', $document->user->getKey()),
            ],
            'company' => $document->company?->getAttribute('name'),
            'organization' => $document->organization === null ? null : [
                'name' => $document->organization->getAttribute('name'),
                'is_stub' => (bool) $document->organization->getAttribute('is_stub'),
            ],
            'base' => $this->printedDocumentBase($document),
            'download_url' => $document->file_status === PrintedDocument::FILE_STORED
                ? route('crm.printed-documents.download', $document->getKey())
                : null,
        ]);

        $options = $this->listOptions($request, 'printed_documents', $clients, $sortBy, $sortOrder, $perPage, $search);
        // Снимок отбора обязан включать все ключи, которые читает страница:
        // отсутствующий ключ фронт трактует как «ничего не выбрано», и чекбоксы
        // вида документа/состояния файла сбрасывались после каждого запроса.
        $options['filters']['types'] = $this->values($request, 'types');
        $options['filters']['file_statuses'] = $this->values($request, 'file_statuses');

        return Inertia::render('Crm/Pages/Documents/PrintedDocuments', array_merge(
            ['documents' => $documents],
            $options,
            [
                'types' => PrintedDocumentType::options(),
                'fileStatuses' => array_map(
                    static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                    array_keys(PrintedDocument::FILE_STATUS_LABELS),
                    array_values(PrintedDocument::FILE_STATUS_LABELS),
                ),
            ],
        ));
    }

    public function printedDocumentsExport(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        abort_unless((bool) config('documents.crm_enabled'), 404);

        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);
        [$sortBy, $sortOrder] = $this->printedDocumentsSort($request);

        $query = $this->printedDocumentsQuery($request, $clients, $this->search($request))
            ->with(['user:id,name,erp_name,email', 'company:id,name,tax_id', 'organization:id,name'])
            ->orderBy($sortBy, $sortOrder);

        $headers = ['Тип', 'Номер', 'Дата', 'Период', 'Клиент', 'Контрагент', 'ИНН', 'Продавец', 'Основание', 'Файл', 'Формат', 'Размер, МБ'];

        $rows = (function () use ($query): \Generator {
            foreach ($query->cursor() as $document) {
                yield [
                    $document->type_label,
                    $document->number,
                    $document->date?->format('d.m.Y'),
                    // Без периода строки актов сверки одного контрагента в реестре
                    // неразличимы: у них совпадает всё, кроме содержимого файла.
                    $document->period_label,
                    $document->user?->display_name,
                    $document->company?->getAttribute('name'),
                    $document->company?->getAttribute('tax_id'),
                    $document->organization?->getAttribute('name'),
                    $this->printedDocumentBase($document)['label'] ?? null,
                    PrintedDocument::FILE_STATUS_LABELS[$document->file_status] ?? $document->file_status,
                    $document->format->label(),
                    $document->size_bytes === null ? null : round($document->size_bytes / 1024 / 1024, 2),
                ];
            }
        })();

        return $exporter->stream(
            'crm-printed-documents-'.now()->format('Y-m-d-His'),
            $headers,
            $rows,
            'Документы',
        );
    }

    /**
     * Скачивание печатной формы менеджером.
     *
     * Файл идёт через приложение, а не прямой ссылкой на диск: иначе счёт-фактура
     * по угаданной ссылке утекла бы мимо скоупа партнёров. Та же причина, что
     * у Crm\AttachmentController.
     *
     * Storage::url() не подходит: строку он вернёт, но бакет приватный и отдаст
     * по ней 403 — получилась бы кнопка, которая всегда не работает.
     */
    public function printedDocumentDownload(Request $request, PrintedDocument $printedDocument): StreamedResponse
    {
        abort_unless((bool) config('documents.crm_enabled'), 404);

        $actor = $this->crmActor($request);
        $clients = $this->visibleClients($request, $actor);

        $visible = PrintedDocument::query()
            ->whereKey($printedDocument->getKey())
            ->where(function (Builder $inner) use ($clients, $request): void {
                $inner->whereIn('user_id', (clone $clients));

                // Документ без партнёра — обмен ещё не сопоставил его с клиентом.
                // Такие видит только тот, кому доступен весь отдел: иначе строка
                // «ничья» была бы доступна любому менеджеру.
                if ($this->seesDepartment($request)) {
                    $inner->orWhereNull('user_id');
                }
            })
            ->exists();

        abort_unless($visible, 404);
        abort_unless($printedDocument->file_status === PrintedDocument::FILE_STORED, 404, 'Файл ещё не готов');

        $disk = Storage::disk($printedDocument->disk);

        abort_unless($printedDocument->path && $disk->exists($printedDocument->path), 404, 'Файл не найден');

        return $disk->download($printedDocument->path, $printedDocument->download_name);
    }

    /**
     * Отбор печатных форм для журнала и выгрузки — один на оба.
     *
     * @param  Builder<User>  $clients
     * @return Builder<PrintedDocument>
     */
    private function printedDocumentsQuery(Request $request, Builder $clients, ?string $search): Builder
    {
        $query = PrintedDocument::query()->where(function (Builder $inner) use ($clients, $request): void {
            $inner->whereIn('user_id', (clone $clients));

            if ($this->seesDepartment($request)) {
                $inner->orWhereNull('user_id');
            }
        });

        if ($search !== null) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('erp_type_name', 'like', $like);
            });
        }

        $types = $this->values($request, 'types');

        if ($types !== []) {
            $query->whereIn('type', array_intersect($types, PrintedDocumentType::values()));
        }

        $fileStatuses = $this->values($request, 'file_statuses');

        if ($fileStatuses !== []) {
            $query->whereIn('file_status', array_intersect($fileStatuses, array_keys(PrintedDocument::FILE_STATUS_LABELS)));
        }

        $this->applyIdFilter($query, 'user_id', $this->values($request, 'partner_ids'));
        $this->applyIdFilter($query, 'company_id', $this->values($request, 'company_ids'));
        $this->applyIdFilter($query, 'organization_id', $this->values($request, 'organization_ids'));

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        return $query;
    }

    /**
     * Своя сортировка: общий sort() умеет `erp_created_at` и суммы, а печатную
     * форму ищут по дате документа и номеру.
     *
     * @return array{0: string, 1: string}
     */
    private function printedDocumentsSort(Request $request): array
    {
        $allowed = ['date', 'number', 'type', 'id'];

        $sortBy = in_array($request->input('sort_by'), $allowed, true)
            ? (string) $request->input('sort_by')
            : 'date';

        return [$sortBy, $request->input('sort_order') === 'asc' ? 'asc' : 'desc'];
    }

    /**
     * Документ-основание печатной формы для показа в списке.
     *
     * @return array{label: string, url: string|null}|null
     */
    private function printedDocumentBase(PrintedDocument $document): ?array
    {
        if ($document->shipment) {
            return [
                'label' => 'Реализация '.($document->shipment->erp_number ?: $document->shipment->number),
                'url' => route('crm.shipments.show', $document->shipment->getKey()),
            ];
        }

        if ($document->order) {
            return [
                'label' => 'Заказ '.($document->order->erp_number ?: $document->order->number),
                'url' => route('crm.orders.show', $document->order->getKey()),
            ];
        }

        return null;
    }

    /**
     * Общая сборка выгрузки: тот же отбор, что на экране, но без страниц.
     *
     * Читаем страницами и складываем уже готовые скалярные строки: держать в
     * памяти десять тысяч моделей со связями дороже самой генерации файла.
     *
     * @param  Builder<Order>|Builder<Shipment>  $query
     * @param  \Closure(list<int>): list<list<scalar|null>>  $itemRows  позиции выгруженных документов
     */
    private function exportDocuments(
        Request $request,
        Builder $query,
        SimpleXlsxExporter $exporter,
        string $filename,
        string $sheetTitle,
        \Closure $itemRows,
    ): StreamedResponse {
        [$sortBy, $sortOrder] = $this->sort($request);
        $organizationsEnabled = (bool) config('erp.organizations.enabled');

        [$rows, $ids] = $this->collectExportRows(
            $query,
            $sortBy,
            $sortOrder,
            fn (\Illuminate\Database\Eloquent\Model $document): array => $this->exportRow($document, $organizationsEnabled),
            [
                'user:id,name,erp_name,email,personal_manager_id',
                'user.personalManager:id,name',
                'company:id,name,tax_id',
                'organization:id,name,is_stub',
                'warehouse:id,name',
            ],
            ['items'],
        );

        $sheets = [[
            'title' => $sheetTitle,
            'headers' => $this->exportHeaders($organizationsEnabled),
            'rows' => $rows,
        ]];

        // Лист позиций — только при фильтре по товару: без него в него ушли бы
        // все строки всех документов, а это уже не выгрузка журнала.
        $items = $ids === [] ? [] : $itemRows($ids);

        if ($items !== []) {
            $sheets[] = [
                'title' => 'Позиции',
                'headers' => ['Документ', 'Дата', 'Партнёр', 'Товар', 'Артикул', 'Бренд', 'Количество', 'Цена', 'Сумма'],
                'rows' => $items,
            ];
        }

        return $exporter->streamSheets($filename, $sheets);
    }

    /**
     * Постраничный сбор строк выгрузки с обрезкой по лимиту.
     *
     * Общий для документов и платежей: ценность метода — стабильная сортировка
     * и честная пометка об обрезке, и дублировать их значило бы однажды получить
     * файл, который выглядит полным, но полным не является.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  \Closure(\Illuminate\Database\Eloquent\Model): list<scalar|null>  $row
     * @param  list<string>  $with
     * @param  list<string>  $withCount
     * @return array{0: list<list<scalar|null>>, 1: list<int>}
     */
    private function collectExportRows(
        Builder $query,
        string $sortBy,
        string $sortOrder,
        \Closure $row,
        array $with = [],
        array $withCount = [],
    ): array {
        $total = (clone $query)->count();
        $rows = [];
        $ids = [];
        $page = 1;

        do {
            $chunk = (clone $query)
                ->with($with)
                ->withCount($withCount)
                // Вторичная сортировка по id: без неё документы с одинаковой
                // датой могут разъехаться между страницами и попасть в файл
                // дважды или не попасть вовсе.
                ->orderBy($sortBy, $sortOrder)
                ->orderBy('id')
                ->forPage($page, self::EXPORT_CHUNK)
                ->get();

            foreach ($chunk as $model) {
                $rows[] = $row($model);
                $ids[] = (int) $model->getKey();

                if (count($rows) >= self::EXPORT_LIMIT) {
                    break 2;
                }
            }

            $page++;
        } while ($chunk->count() === self::EXPORT_CHUNK);

        $shown = count($rows);

        // Обрезку показываем прямо в файле: молча урезанная выгрузка выглядит
        // как полная, и по ней делают выводы.
        if ($total > $shown) {
            $rows[] = [];
            $rows[] = ['Показаны первые '.$shown.' документов из '.$total.' — уточните фильтры'];
        }

        return [$rows, $ids];
    }

    /**
     * @return list<string>
     */
    private function exportHeaders(bool $organizationsEnabled): array
    {
        return array_values(array_filter([
            'Документ',
            'Номер на сайте',
            'Дата',
            'Статус',
            'Партнёр',
            'Email',
            'Менеджер',
            'Контрагент',
            'ИНН',
            $organizationsEnabled ? 'Организация' : null,
            'Склад',
            'Позиций',
            'Сумма',
            'Валюта',
        ]));
    }

    /**
     * Строка документа для XLSX. Сумма уходит числом, а не «1 200,00 ₽»:
     * иначе в Excel по ней не посчитать итог.
     *
     * @param  Order|Shipment  $document
     * @return list<scalar|null>
     */
    private function exportRow(\Illuminate\Database\Eloquent\Model $document, bool $organizationsEnabled): array
    {
        $isOrder = $document instanceof Order;
        $date = $isOrder
            ? ($document->erp_created_at ?? $document->created_at)
            : ($document->erp_created_at ?? $document->date);

        /** @var User|null $client */
        $client = $document->getRelationValue('user');
        $company = $document->getRelationValue('company');
        $organization = $document->getRelationValue('organization');
        $warehouse = $document->getRelationValue('warehouse');

        $row = [
            'number' => $document->erp_number ?: $document->number ?: '#'.$document->getKey(),
            'site_number' => $document->number,
            'date' => $date?->format('d.m.Y H:i'),
            'status' => $isOrder ? $document->status->label() : $document->status_label,
            'client' => $client?->display_name,
            'email' => $client?->email,
            'manager' => $client?->personalManager?->name,
            'company' => $company?->getAttribute('name'),
            'tax_id' => $company?->getAttribute('tax_id'),
            'organization' => $organization?->getAttribute('name'),
            'warehouse' => $warehouse?->getAttribute('name'),
            'items_count' => (int) ($document->items_count ?? 0),
            'total' => round((float) $document->total_amount, 2),
            'currency' => $document->currency_code ?: 'RUB',
        ];

        // Колонка организации следует за витриной: справочник ещё не заполнен —
        // её нет ни на экране, ни в файле.
        if (! $organizationsEnabled) {
            unset($row['organization']);
        }

        return array_values($row);
    }

    /**
     * Позиции заказов по выбранным товарам.
     *
     * @param  list<int>  $orderIds
     * @param  list<int>  $productIds
     * @return list<list<scalar|null>>
     */
    private function orderItemRows(array $orderIds, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('product_id', $productIds)
            ->with(['order:id,number,erp_number,created_at,erp_created_at,user_id', 'order.user:id,name,erp_name', 'product:id,sku'])
            ->get()
            ->map(fn (OrderItem $item): array => [
                $item->order?->erp_number ?: $item->order?->number ?: '#'.$item->getAttribute('order_id'),
                ($item->order->erp_created_at ?? $item->order->created_at)?->format('d.m.Y H:i'),
                $item->order?->user?->display_name,
                $item->name ?: $item->product?->name,
                $item->product?->sku,
                $item->brand_name_snapshot,
                (int) $item->quantity,
                round((float) $item->final_price, 2),
                round((float) $item->subtotal, 2),
            ])
            ->all();
    }

    /**
     * Позиции реализаций по выбранным товарам.
     *
     * @param  list<int>  $shipmentIds
     * @param  list<int>  $productIds
     * @return list<list<scalar|null>>
     */
    private function shipmentItemRows(array $shipmentIds, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ShipmentItem::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->whereIn('product_id', $productIds)
            ->with(['shipment:id,number,erp_number,date,erp_created_at,user_id', 'shipment.user:id,name,erp_name', 'product:id,sku'])
            ->get()
            ->map(fn (ShipmentItem $item): array => [
                $item->shipment?->erp_number ?: $item->shipment?->number ?: '#'.$item->getAttribute('shipment_id'),
                ($item->shipment->erp_created_at ?? $item->shipment->date)?->format('d.m.Y H:i'),
                $item->shipment?->user?->display_name,
                $item->product_name_snapshot ?: $item->product?->name,
                $item->product?->sku,
                $item->brand_name_snapshot,
                (int) $item->quantity,
                round((float) $item->price, 2),
                round((float) $item->total, 2),
            ])
            ->all();
    }

    /**
     * Отобранные заказы — без сортировки и постраничности.
     *
     * Один источник для экрана и для выгрузки: разъедься они, XLSX начал бы
     * показывать не то, что менеджер видит на экране, и доверять файлу стало
     * бы нельзя.
     *
     * @param  Builder<User>  $clients
     * @return Builder<Order>
     */
    private function ordersQuery(Request $request, Builder $clients, ?string $search): Builder
    {
        $query = Order::query()->whereIn('user_id', (clone $clients));

        $this->applySearch($query, $search, 'name');
        $this->applyCommonFilters(
            $query,
            $request,
            'erp_created_at',
            array_map(fn (OrderStatus $case): string => $case->value, OrderStatus::cases()),
        );

        return $query;
    }

    /**
     * Отобранные реализации — без сортировки и постраничности.
     *
     * @param  Builder<User>  $clients
     * @return Builder<Shipment>
     */
    private function shipmentsQuery(Request $request, Builder $clients, ?string $search): Builder
    {
        $query = Shipment::query()->whereIn('user_id', (clone $clients));

        // В позициях реализации название лежит снимком: 1С шлёт его строкой,
        // и товара сайта у позиции может не быть вовсе.
        $this->applySearch($query, $search, 'product_name_snapshot');
        $this->applyCommonFilters($query, $request, 'erp_created_at', array_column(self::SHIPMENT_STATUSES, 'value'));

        return $query;
    }

    /**
     * Отбор платежей — единый источник для экрана и для XLSX.
     *
     * @param  Builder<User>  $clients
     * @return Builder<Payment>
     */
    private function paymentsQuery(Request $request, Builder $clients, ?string $search): Builder
    {
        $query = Payment::query()->whereIn('user_id', (clone $clients));

        if ($search !== null) {
            $like = '%'.$search.'%';

            $query->where(function (Builder $inner) use ($search, $like): void {
                $inner->where('number', 'like', $like)
                    ->orWhere('bank_number', 'like', $like)
                    ->orWhere('uip', 'like', $like)
                    ->orWhere('id', $search)
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', $like)
                        ->orWhere('erp_name', 'like', $like)
                        ->orWhere('email', 'like', $like));
            });
        }

        // Дата платежа — бизнес-дата документа. В отличие от заказов и реализаций
        // здесь это `date`, а не `erp_created_at`: у платежа они совпадают
        // по смыслу, а `date` заполнена всегда.
        $this->applyPartyFilters($query, $request, 'date');
        $this->applyAmountFilters($query, $request, 'amount');

        $directions = array_values(array_intersect(
            $this->values($request, 'directions', 'direction'),
            array_column(self::PAYMENT_DIRECTIONS, 'value'),
        ));

        if ($directions !== []) {
            $query->whereIn('direction', $directions);
        }

        return $query;
    }

    /**
     * Строка платежа для журнала.
     *
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $payment): array
    {
        return [
            'id' => (int) $payment->getKey(),
            'number' => $payment->number,
            'date_label' => $payment->date?->format('d.m.Y H:i'),
            'direction' => $payment->direction,
            'direction_label' => $payment->direction_label,
            'direction_color' => $payment->direction === Payment::DIRECTION_OUT ? 'red' : 'green',
            'bank_number' => $payment->bank_number,
            'bank_confirmed' => (bool) $payment->bank_confirmed,
            'total_label' => $this->money((float) $payment->amount, $payment->currency_code),
            'client' => $payment->user === null ? null : [
                'id' => (int) $payment->user->getKey(),
                'name' => $payment->user->display_name,
                // Персональный менеджер партнёра — подписью под именем. РОП
                // читает журнал отдела построчно, и без этого приходилось
                // открывать карточку, чтобы понять, чей это клиент.
                'manager_name' => $payment->user->personalManager?->getAttribute('name'),
                'url' => route('crm.clients.show', $payment->user->getKey()),
            ],
            'company' => $payment->company?->getAttribute('name'),
            'organization' => $payment->organization === null ? null : [
                'name' => $payment->organization->getAttribute('name'),
                'is_stub' => (bool) $payment->organization->getAttribute('is_stub'),
            ],
            'url' => route('crm.payments.show', $payment->getKey()),
        ];
    }

    /**
     * Строка платежа для XLSX. Суммы уходят числами: иначе в Excel
     * по ним не посчитать итог.
     *
     * @return list<scalar|null>
     */
    private function paymentExportRow(\Illuminate\Database\Eloquent\Model $payment, bool $organizationsEnabled): array
    {
        /** @var Payment $payment */
        return [
            $payment->number,
            $payment->date?->format('d.m.Y H:i'),
            $payment->direction_label,
            $payment->user?->display_name,
            $payment->user?->email,
            $payment->user?->personalManager?->name,
            $payment->company?->getAttribute('name'),
            $payment->company?->getAttribute('tax_id'),
            // Колонка организации есть в строке ровно тогда, когда она есть
            // в заголовках: иначе значения съедут относительно шапки.
            ...($organizationsEnabled ? [$payment->organization?->getAttribute('name')] : []),
            $payment->bank_number,
            $payment->bank_confirmed ? 'Да' : 'Нет',
            round((float) $payment->amount, 2),
            $payment->currency_code,
        ];
    }

    /**
     * Строка поиска: номер документа, партнёр или товар в позициях.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySearch(Builder $query, ?string $search, string $itemNameColumn): void
    {
        if ($search === null) {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function (Builder $inner) use ($search, $like, $itemNameColumn): void {
            $inner->where('number', 'like', $like)
                ->orWhere('erp_number', 'like', $like)
                ->orWhere('id', $search)
                ->orWhereHas('user', fn ($user) => $user
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like))
                ->orWhereHas('items', fn ($item) => $item->where($itemNameColumn, 'like', $like));
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sort(Request $request): array
    {
        $sortBy = in_array($request->input('sort_by'), self::SORTS, true)
            ? (string) $request->input('sort_by')
            : 'erp_created_at';

        return [$sortBy, $request->input('sort_order') === 'asc' ? 'asc' : 'desc'];
    }

    /**
     * Фильтры, одинаковые у обоих списков: статусы, партнёры, контрагенты,
     * организации, склады, товар в позициях, даты, суммы.
     *
     * Менеджер сюда не попал намеренно: он сужает набор партнёров, а не
     * документов, и применяется раньше — в visibleClients(). Партнёр же —
     * обычная колонка user_id, и чужой id безопасен: скоуп партнёров уже сузил
     * выборку, пересечение с ним ничего не открывает.
     *
     * Шаблонный параметр, а не объединение Order|Shipment: дженерик Builder
     * инвариантен, и объединение не приняло бы ни один из двух конкретных типов.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  list<string>  $allowedStatuses
     */
    private function applyCommonFilters(Builder $query, Request $request, string $dateColumn, array $allowedStatuses): void
    {
        $statuses = array_values(array_intersect($this->values($request, 'statuses', 'status'), $allowedStatuses));

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $this->applyPartyFilters($query, $request, $dateColumn);

        $this->applyIdFilter($query, 'warehouse_id', $this->values($request, 'warehouse_ids', 'warehouse_id'));

        // Товар — «в каком документе он есть»: whereHas по позициям, а не join,
        // иначе документ с двумя выбранными товарами задвоился бы в списке.
        $productIds = $this->ids($request, 'product_ids');

        if ($productIds !== []) {
            $query->whereHas('items', fn (Builder $item) => $item->whereIn('product_id', $productIds));
        }

        $this->applyAmountFilters($query, $request, 'total_amount');
    }

    /**
     * Фильтры, общие вообще всем журналам: стороны документа, организация, даты.
     *
     * Вынесено из applyCommonFilters ради платежей: у них нет ни статусов 1С,
     * ни позиций, ни колонки total_amount, но партнёр, контрагент, организация
     * и период отбираются ровно так же.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     */
    private function applyPartyFilters(Builder $query, Request $request, string $dateColumn): void
    {
        $this->applyIdFilter($query, 'user_id', $this->values($request, 'partner_ids'));
        $this->applyIdFilter($query, 'company_id', $this->values($request, 'company_ids'));
        // 'none' — документы без организации: их много в переходный период,
        // и именно их бывает нужно отобрать.
        $this->applyIdFilter($query, 'organization_id', $this->values($request, 'organization_ids', 'organization_id'));

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate($dateColumn, '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate($dateColumn, '<=', $dateTo);
        }
    }

    /**
     * Диапазон суммы. Колонка разная: у документов total_amount, у платежей amount.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     */
    private function applyAmountFilters(Builder $query, Request $request, string $column): void
    {
        if ($amountFrom = $request->input('amount_from')) {
            $query->where($column, '>=', (float) $amountFrom);
        }

        if ($amountTo = $request->input('amount_to')) {
            $query->where($column, '<=', (float) $amountTo);
        }
    }

    /**
     * Общие пропсы списков: справочники фильтров и снимок текущего отбора.
     *
     * @param  'orders'|'shipments'|'payments'|'printed_documents'  $table
     * @param  Builder<User>  $clients  скоуп партнёров актора (уже с фильтром по менеджеру)
     * @return array<string, mixed>
     */
    private function listOptions(Request $request, string $table, Builder $clients, string $sortBy, string $sortOrder, int $perPage, ?string $search): array
    {
        $organizationsEnabled = (bool) config('erp.organizations.enabled');
        $seesAll = $this->seesDepartment($request);
        $productIds = $this->ids($request, 'product_ids');

        return [
            'organizations' => $organizationsEnabled
                ? Organization::query()->ordered()->where('is_stub', false)->get(['id', 'name'])
                : [],
            'organizationsEnabled' => $organizationsEnabled,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'partners' => $this->partnerOptions($table, $clients),
            // Справочник контрагентов зависит от выбранных партнёров: у партнёра
            // юрлиц один-два, а в общем списке их сотни.
            'companies' => $this->companyOptions(
                $table,
                $clients,
                $this->ids($request, 'partner_ids'),
                $this->ids($request, 'company_ids'),
            ),
            // Менеджер — только РОПу: у рядового менеджера в скоупе и так
            // только свои партнёры, фильтр был бы кнопкой без эффекта.
            'managers' => $seesAll ? $this->managerOptions() : [],
            'seesAll' => $seesAll,
            // Выбранные товары приезжают целиком: в URL только id, а рисовать
            // фильтр нужно с названиями.
            'selectedProducts' => $this->selectedProducts($productIds),
            'filters' => [
                'scope' => CrmScope::fromRequest($request, $this->crmActor($request))->value,
                'search' => $search,
                'statuses' => $this->values($request, 'statuses', 'status'),
                'partner_ids' => $this->ids($request, 'partner_ids'),
                'company_ids' => $this->values($request, 'company_ids'),
                'manager_ids' => $seesAll ? $this->ids($request, 'manager_ids') : [],
                'organization_ids' => $this->values($request, 'organization_ids', 'organization_id'),
                'warehouse_ids' => $this->values($request, 'warehouse_ids', 'warehouse_id'),
                'product_ids' => $productIds,
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'amount_from' => $request->input('amount_from'),
                'amount_to' => $request->input('amount_to'),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ];
    }

    /**
     * Скоуп партнёров актора с учётом фильтра по менеджеру.
     *
     * Менеджер сужает не документ, а набор партнёров, поэтому живёт здесь, а не
     * в applyCommonFilters: тем же скоупом собираются справочники фильтров, и
     * выбор менеджера заодно сужает список его партнёров и контрагентов.
     *
     * Фильтр по партнёру сюда не входит намеренно — иначе, выбрав одного,
     * второго уже не добавить: справочник схлопнулся бы до выбранного.
     *
     * @return Builder<User>
     */
    private function visibleClients(Request $request, User $actor): Builder
    {
        $query = User::query()
            ->inCrmScope($actor, CrmScope::fromRequest($request, $actor))
            ->select('users.id');

        // Отбор по менеджеру сужает уже видимое: тому, кто отдел не видит,
        // чужой id в запросе всё равно ничего не даст.
        if ($this->seesDepartment($request)) {
            $managerIds = $this->ids($request, 'manager_ids');

            if ($managerIds !== []) {
                $query->whereIn('users.personal_manager_id', $managerIds);
            }
        }

        return $query;
    }

    /**
     * Мультивыбор по числовой колонке. Псевдо-значение 'none' — «пусто»
     * (документ без организации, без склада, без контрагента).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $values
     */
    private function applyIdFilter(Builder $query, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $withoutNone = array_values(array_filter($values, fn (string $value): bool => $value !== 'none'));
        $ids = array_values(array_filter(array_map('intval', $withoutNone), fn (int $id): bool => $id > 0));
        $hasNone = count($withoutNone) !== count($values);

        if ($ids === [] && ! $hasNone) {
            return;
        }

        $query->where(function (Builder $inner) use ($column, $ids, $hasNone): void {
            if ($ids !== []) {
                $inner->whereIn($column, $ids);
            }

            if ($hasNone) {
                $ids === [] ? $inner->whereNull($column) : $inner->orWhereNull($column);
            }
        });
    }

    /**
     * Значения мультивыбора из запроса. Старые ссылки со скалярным параметром
     * (?status=new) продолжают работать — их читает $legacyKey.
     *
     * @return list<string>
     */
    private function values(Request $request, string $key, ?string $legacyKey = null): array
    {
        $input = $request->input($key);

        if ($input === null && $legacyKey !== null) {
            $input = $request->input($legacyKey);
        }

        if ($input === null || $input === '') {
            return [];
        }

        $values = array_map(
            fn (mixed $value): string => trim((string) $value),
            is_array($input) ? $input : [$input],
        );

        return array_values(array_unique(array_filter($values, fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<int>
     */
    private function ids(Request $request, string $key): array
    {
        $ids = array_map('intval', $this->values($request, $key));

        return array_values(array_filter($ids, fn (int $id): bool => $id > 0));
    }

    /**
     * Партнёры — партнёры, у которых в этом журнале есть хотя бы один документ.
     *
     * Берём из самих документов, а не из списка партнёров: у РОПа партнёров
     * восемь сотен, и большинство в конкретном журнале не встречается ни разу.
     *
     * @param  'orders'|'shipments'|'payments'|'printed_documents'  $table
     * @param  Builder<User>  $clients
     * @return list<array{id: int, name: string}>
     */
    private function partnerOptions(string $table, Builder $clients): array
    {
        // Сначала голые id из журнала, потом сами карточки: join с users по всей
        // таблице документов заставил бы MySQL читать строки, а так хватает
        // индекса по user_id.
        $ids = DB::table($table)
            ->whereNull($table.'.deleted_at')
            ->whereIn($table.'.user_id', (clone $clients))
            ->distinct()
            ->pluck($table.'.user_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'erp_name', 'email'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->display_name,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Контрагенты — юрлица, на которые проведены документы журнала.
     *
     * @param  'orders'|'shipments'|'payments'|'printed_documents'  $table
     * @param  Builder<User>  $clients
     * @param  list<int>  $partnerIds  выбранные партнёры — сужают справочник
     * @param  list<int>  $keepIds  уже выбранные контрагенты — не выпадают из списка
     * @return list<array{id: int, name: string}>
     */
    private function companyOptions(string $table, Builder $clients, array $partnerIds = [], array $keepIds = []): array
    {
        $query = DB::table($table)
            ->whereNull($table.'.deleted_at')
            ->whereIn($table.'.user_id', (clone $clients))
            ->whereNotNull($table.'.company_id');

        // Обратной зависимости (контрагент сужает партнёров) нет намеренно:
        // выбрав одного контрагента, второго партнёра было бы уже не добавить —
        // справочник схлопнулся бы до выбранного. Та же причина, по которой
        // фильтр по партнёру не входит в visibleClients.
        if ($partnerIds !== []) {
            $query->whereIn($table.'.user_id', $partnerIds);
        }

        $ids = $query->distinct()->pluck($table.'.company_id')->all();

        // Уже выбранные контрагенты остаются в списке, даже если выпали из
        // сужения: иначе снять фильтр, который сам себя спрятал, было бы нечем.
        $ids = array_values(array_unique(array_merge(
            array_map('intval', $ids),
            array_map('intval', $keepIds),
        )));

        if ($ids === []) {
            return [];
        }

        return Company::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'legal_name', 'tax_id'])
            ->map(fn (Company $company): array => [
                'id' => (int) $company->getKey(),
                'name' => (string) ($company->name ?: $company->legal_name ?: ($company->tax_id ? 'ИНН '.$company->tax_id : 'Контрагент #'.$company->getKey())),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
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
     * Подсказки товаров для фильтра «товар в документе».
     *
     * Отдельный маршрут вместо `crm.products.search`: тот закрыт правом
     * `crm-analytics.view`, которого у рядового менеджера может не быть, а
     * журналы документов открыты по `crm-clients.view`.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $products = Product::search($query)
            ->take(20)
            ->get()
            ->load(['media', 'brand:id,name'])
            ->map(fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'brand_name' => $product->brand?->name,
            ]);

        return response()->json($products);
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array{id: int, name: string, sku: string|null, image_url: string, brand_name: string|null}>
     */
    private function selectedProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->with(['media', 'brand:id,name'])
            ->get(['id', 'name', 'sku', 'brand_id'])
            ->map(fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'brand_name' => $product->brand?->name,
            ])
            ->all();
    }

    private function search(Request $request): ?string
    {
        $search = trim((string) $request->input('search', ''));

        return $search === '' ? null : mb_substr($search, 0, 120);
    }

    public function order(Request $request, int $order): Response
    {
        $actor = $this->crmActor($request);

        /** @var Order $model */
        $model = $this->resolver->resolveForActor($actor, CrmEntityMap::ORDER, $order);

        $model->load([
            'user:id,name,erp_name,email,phone,personal_manager_id',
            'company:id,name,tax_id',
            // is_stub обязателен в выборке: без него незаведённое юрлицо
            // показалось бы менеджеру голым UUID-ом вместо названия.
            'organization:id,name,is_stub',
            'warehouse:id,name',
            'items.product:id,name,slug,sku',
            'shipments' => fn ($query) => $query->orderByDesc('date'),
        ]);

        return Inertia::render('Crm/Pages/Documents/Show', [
            'document' => [
                'type' => CrmEntityMap::ORDER,
                'id' => (int) $model->getKey(),
                'title' => 'Заказ №'.($model->erp_number ?: $model->number ?: $model->getKey()),
                'number' => $model->number,
                'erp_number' => $model->erp_number,
                'status_label' => $model->status->label(),
                'status_color' => $model->status->color(),
                'date_label' => ($model->erp_created_at ?? $model->created_at)?->format('d.m.Y H:i'),
                'created_at_label' => $model->created_at?->format('d.m.Y H:i'),
                'total_label' => $this->money((float) $model->total_amount, $model->currency_code),
                // v15.16.0: предоплата по заказу из расшифровки платежей 1С.
                // Реализации по такому заказу может ещё не быть — накладную
                // эта сумма не гасит, но менеджер должен её видеть
                'prepaid_label' => (float) $model->prepaid_amount > 0
                    ? $this->money((float) $model->prepaid_amount, $model->currency_code)
                    : null,
                'comment' => $model->comment,
                'manager_comment' => $model->manager_comment,
                'delivery_address' => $model->delivery_address,
                'organization' => $this->organization($model),
                'warehouse' => $this->warehouse($model),
                'company' => $model->company?->getAttribute('name'),
                'admin_url' => $actor->hasAdminAccess() ? route('admin.orders.show', $model->getKey()) : null,
                'items' => $model->items->map(fn (OrderItem $item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->name ?: $item->product?->name ?: 'Позиция №'.$item->getKey(),
                    'sku' => $item->product?->sku,
                    'brand' => $item->brand_name_snapshot,
                    'quantity' => (int) $item->quantity,
                    'price_label' => $this->money((float) $item->final_price, $model->currency_code),
                    'total_label' => $this->money((float) $item->subtotal, $model->currency_code),
                    // v15.16.0: недобор — строка отменена в 1С и в сумму заказа
                    // не входит. Менеджер должен видеть это в карточке, иначе
                    // сумма позиций не сойдётся с итогом документа
                    'cancelled' => (bool) $item->cancelled,
                ])->all(),
                'related' => $model->shipments->map(fn (Shipment $shipment): array => [
                    'type' => CrmEntityMap::SHIPMENT,
                    'id' => (int) $shipment->getKey(),
                    'title' => 'Реализация №'.($shipment->erp_number ?: $shipment->number ?: $shipment->getKey()),
                    'date_label' => ($shipment->erp_created_at ?? $shipment->date)?->format('d.m.Y'),
                    'total_label' => $this->money((float) $shipment->total_amount, $shipment->currency_code),
                    'url' => route('crm.shipments.show', $shipment->getKey()),
                ])->all(),
            ],
            'client' => $this->clientPayload($model->user),
        ]);
    }

    public function shipment(Request $request, int $shipment): Response
    {
        $actor = $this->crmActor($request);

        /** @var Shipment $model */
        $model = $this->resolver->resolveForActor($actor, CrmEntityMap::SHIPMENT, $shipment);

        $model->load([
            'user:id,name,erp_name,email,phone,personal_manager_id',
            'company:id,name,tax_id',
            // is_stub обязателен в выборке: без него незаведённое юрлицо
            // показалось бы менеджеру голым UUID-ом вместо названия.
            'organization:id,name,is_stub',
            'warehouse:id,name',
            'items.product:id,name,slug,sku',
        ]);

        // Заказы, по которым сделана отгрузка: связь идёт через order_uuid
        // в позициях, а не прямой ссылкой на заказ — одна отгрузка может
        // закрывать несколько заказов.
        $related = [];

        foreach ($model->getRelatedOrders() as $order) {
            /** @var Order $order */
            $related[] = [
                'type' => CrmEntityMap::ORDER,
                'id' => (int) $order->getKey(),
                'title' => 'Заказ №'.($order->erp_number ?: $order->number ?: $order->getKey()),
                'date_label' => ($order->erp_created_at ?? $order->created_at)?->format('d.m.Y'),
                'total_label' => $this->money((float) $order->total_amount, $order->currency_code),
                'url' => route('crm.orders.show', $order->getKey()),
            ];
        }

        return Inertia::render('Crm/Pages/Documents/Show', [
            'document' => [
                'type' => CrmEntityMap::SHIPMENT,
                'id' => (int) $model->getKey(),
                'title' => 'Реализация №'.($model->erp_number ?: $model->number ?: $model->getKey()),
                'number' => $model->number,
                'erp_number' => $model->erp_number,
                'status_label' => $model->status_label,
                'status_color' => $model->status === 'completed' ? 'green' : 'blue',
                'date_label' => ($model->erp_created_at ?? $model->date)?->format('d.m.Y H:i'),
                'created_at_label' => $model->created_at?->format('d.m.Y H:i'),
                'total_label' => $this->money((float) $model->total_amount, $model->currency_code),
                // v15.16.0: счёт-фактура из 1С — справка для менеджера и бухгалтерии партнёра
                // v15.16.1: печатный номер приоритетнее внутреннего — менеджер
                // и партнёр говорят про одну и ту же бумагу
                'invoice_label' => ($model->invoice_number_display ?: $model->invoice_number)
                    ? trim(($model->invoice_number_display ?: $model->invoice_number)
                        .' от '.($model->invoice_date?->format('d.m.Y') ?? '—'))
                    : null,
                'comment' => null,
                'manager_comment' => null,
                'delivery_address' => null,
                'organization' => $this->organization($model),
                'warehouse' => $this->warehouse($model),
                'company' => $model->company?->getAttribute('name'),
                'admin_url' => $actor->hasAdminAccess() ? route('admin.shipments.show', $model->getKey()) : null,
                'items' => $model->items->map(fn (ShipmentItem $item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->product_name_snapshot ?: $item->product?->name ?: 'Позиция №'.$item->getKey(),
                    'sku' => $item->product?->sku,
                    'brand' => $item->brand_name_snapshot,
                    'quantity' => (int) $item->quantity,
                    'price_label' => $this->money((float) $item->price, $model->currency_code),
                    'total_label' => $this->money((float) $item->total, $model->currency_code),
                ])->all(),
                'related' => $related,
                // Оплата реализации: чем закрыта и сколько осталось. По денормали-
                // зованным полям — их ведёт проекция плановых строк регистра
                // (SettlementProjector), а не расчёт сайта.
                'payment_summary' => [
                    'status' => $model->payment_status,
                    'status_label' => $model->payment_status_label,
                    'paid_label' => $this->money((float) $model->paid_amount, $model->currency_code),
                    'unpaid_label' => $this->money($model->unpaid_amount, $model->currency_code),
                    'total_label' => $this->money((float) $model->total_amount, $model->currency_code),
                ],
                // Список закрывших платежей снят вместе с расшифровкой: 1С её
                // не присылает, а угадывать связь по датам и суммам нельзя.
                'payments' => [],
                // v15.12.0: план оплаты рядом с фактом. Суммы в валюте документа —
                // сотрудник сверяет карточку с 1С, а не со своим кабинетом.
                'payment_schedule' => PaymentSchedulePresenter::forShipment($model),
                'currency_code' => $model->currency_code,
            ],
            'client' => $this->clientPayload($model->user),
        ]);
    }

    /**
     * Наша организация документа — юрлицо, на которое 1С его провела.
     *
     * Показ гейтит `ORGANIZATIONS_ENABLED`, ровно как в админке и ЛК: приём из 1С
     * работает всегда, а витрина включается, когда справочник заполнен.
     *
     * @param  Order|Shipment  $model
     * @return array{name: string, is_stub: bool}|null
     */
    private function organization(\Illuminate\Database\Eloquent\Model $model): ?array
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $organization = $model->getRelationValue('organization');

        if (! $organization instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }

        return [
            'name' => (string) $organization->getAttribute('name'),
            'is_stub' => (bool) $organization->getAttribute('is_stub'),
        ];
    }

    /**
     * Склад отгрузки документа — определён 1С, сайт только показывает.
     *
     * @param  Order|Shipment  $model
     */
    private function warehouse(\Illuminate\Database\Eloquent\Model $model): ?string
    {
        if (! config('erp.organizations.enabled')) {
            return null;
        }

        $warehouse = $model->getRelationValue('warehouse');

        return $warehouse instanceof \Illuminate\Database\Eloquent\Model
            ? (string) $warehouse->getAttribute('name')
            : null;
    }

    /**
     * Партнёр документа — шапка карточки и ссылка обратно в его ленту.
     *
     * @return array<string, mixed>|null
     */
    private function clientPayload(?\App\Models\User $client): ?array
    {
        if ($client === null) {
            return null;
        }

        return [
            'id' => (int) $client->getKey(),
            'name' => $client->display_name,
            'personal_name' => $client->personal_name_if_differs,
            'email' => $client->email,
            'phone' => $client->phone,
            'url' => route('crm.clients.show', $client->getKey()),
        ];
    }

    /**
     * Сумма с валютой — форматируется на сервере, как и даты.
     */
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
}
