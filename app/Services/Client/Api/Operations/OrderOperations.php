<?php

namespace App\Services\Client\Api\Operations;

use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\User;
use App\Services\Client\Api\CompanyContext;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\GateClosed;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Erp\OrderReservePublisher;
use App\Services\Order\ApiOrderPlacement;
use App\Services\Order\ClientOrderActions;
use App\Services\Order\ClientOrderPresenter;
use App\Services\Order\ClientOrderQuery;
use App\Services\Order\OrderChangeFeed;
use App\Services\Order\OrderRepeater;
use App\Services\Order\PlacementRequest;
use App\Support\Client\ClientApiSource;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Validation\ValidationException;

/**
 * Заказы клиента: список, карточка, журнал изменений состава.
 *
 * Чтение — те же выборка и представление, что у кабинета ({@see ClientOrderQuery},
 * {@see ClientOrderPresenter}): агент видит ровно то, что клиент видит на экране,
 * ни строкой больше. Чужой заказ — «не найдено», а не «запрещено».
 */
class OrderOperations implements OperationProvider
{
    use ResolvesClientEntities;

    public function __construct(
        private readonly ClientOrderQuery $orders,
        private readonly ClientOrderPresenter $presenter,
        private readonly OrderChangeFeed $changes,
        private readonly CompanyContext $companies,
        private readonly ApiOrderPlacement $placement,
        private readonly ClientOrderActions $actions,
        private readonly OrderReservePublisher $publisher,
        private readonly OrderRepeater $repeater,
    ) {}

    public static function section(): array
    {
        return ['orders', 'Заказы'];
    }

    public static function operations(): array
    {
        // Порядок важен: `orders/changes` регистрируется раньше `orders/{order}`,
        // иначе слово «changes» прошло бы ограничение пути как номер заказа.
        return [
            new Operation(
                id: 'orders.list',
                section: 'orders',
                method: 'GET',
                uri: 'orders',
                summary: 'Список заказов клиента с фильтрами и курсорной пагинацией',
                description: 'Все заказы учётной записи, включая предзаказы и промо-документы (`type`). '
                    .'Суммы — в валюте документа, как в 1С. Новые первыми; следующая страница — по `meta.next_cursor`. '
                    .'`updated_since` — для инкрементальной синхронизации по времени изменения на сайте.',
                params: [
                    Param::list('status', 'Статусы заказа (список значений)', 'string'),
                    Param::string('type', 'Тип документа', enum: array_column(OrderType::cases(), 'value')),
                    Param::integer('company_id', 'Юрлицо клиента (companies[].id из /me)'),
                    Param::string('date_from', 'Оформлены не раньше даты, ГГГГ-ММ-ДД', rules: ['date_format:Y-m-d']),
                    Param::string('date_to', 'Оформлены не позже даты, ГГГГ-ММ-ДД', rules: ['date_format:Y-m-d']),
                    Param::string('search', 'Поиск по номеру, товару, бренду, комментарию или контрагенту', rules: ['max:100']),
                    Param::string('updated_since', 'Изменены после момента (дата или дата-время)', rules: ['date']),
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Размер страницы, до '.Envelope::PER_PAGE_MAX, rules: ['min:1']),
                ],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'orders.changes',
                section: 'orders',
                method: 'GET',
                uri: 'orders/changes',
                summary: 'Журнал изменений товарного состава заказов',
                description: 'Движения по позициям заказов, свёрнутые к итогу «было → стало» по товару: '
                    .'`added` (0→N), `removed` (N→0), `changed` (N→M) — правки состава (`kind=edit`); '
                    .'`not_accepted` (N→0), `partial` (N→M) — недостача при приёме заказа через API (`kind=api`). '
                    .'Новые изменения первыми, постраничная выдача.',
                params: [
                    Param::string('type', 'Тип изменения', enum: OrderChangeFeed::TYPES),
                    Param::string('date_from', 'Изменения не раньше даты, ГГГГ-ММ-ДД', rules: ['date_format:Y-m-d']),
                    Param::string('date_to', 'Изменения не позже даты, ГГГГ-ММ-ДД', rules: ['date_format:Y-m-d']),
                    Param::integer('page', 'Номер страницы', rules: ['min:1']),
                    Param::integer('per_page', 'Размер страницы, до '.Envelope::PER_PAGE_MAX_FEED, rules: ['min:1']),
                ],
                handler: [self::class, 'changes'],
            ),
            new Operation(
                id: 'orders.get',
                section: 'orders',
                method: 'GET',
                uri: 'orders/{order}',
                summary: 'Карточка заказа: состав, реализации, история статусов, изменения',
                description: 'Заказ по id, номеру (1С или сайта) либо uuid. Строки, отменённые в 1С при недоборе, '
                    .'остаются в составе с `cancelled=true` и в сумму не входят. Предоплата — только при открытых финансах.',
                params: [
                    Param::string('order', 'Заказ: id, номер или uuid', required: true),
                ],
                handler: [self::class, 'get'],
            ),
            new Operation(
                id: 'orders.create',
                section: 'orders',
                method: 'POST',
                uri: 'orders',
                summary: 'Создать заказ по списку товаров (дружественный приём)',
                description: 'Заказ принимается, даже если часть позиций недоступна: недостающее не блокирует заказ, '
                    .'а попадает в `meta.not_accepted` (в заказ не вошли) и `meta.partial` (приняты не полностью) и в журнал '
                    .'изменений. Наличие и предзаказ уезжают отдельными заказами одного оформления (`checkout_uuid`). '
                    .'`apply_promotions=true` начисляет акции по принятым позициям и создаёт промо-заказы — платные '
                    .'позиции подлежат оплате. `reserve=true` — удержание складской части (только участникам режима). '
                    .'Юрлицо — `company_id` (умолчание — основная компания) либо `inn`. '
                    .'**Заголовок Idempotency-Key обязателен**: повтор с тем же ключом не создаст второй заказ.',
                params: [
                    Param::list('products', 'Позиции {identifier, quantity}', 'object', true, ['min:1', 'max:500']),
                    Param::integer('company_id', 'Юрлицо-покупатель (по умолчанию основная компания)', rules: ['min:1']),
                    Param::string('inn', 'ИНН юрлица вместо company_id', rules: ['max:12']),
                    Param::string('delivery_method', 'Способ получения', enum: ['delivery', 'pickup']),
                    Param::string('address', 'Адрес доставки', rules: ['max:500'], nullable: true),
                    Param::string('comment', 'Комментарий к заказу', rules: ['max:1000'], nullable: true),
                    Param::boolean('apply_promotions', 'Начислить акции по принятым позициям'),
                    Param::boolean('reserve', 'Поставить складскую часть в резерв'),
                ],
                handler: [self::class, 'create'],
                mutating: true,
                idempotent: true,
                idempotencyRequired: true,
                companyScoped: true,
            ),
            new Operation(
                id: 'orders.cancel',
                section: 'orders',
                method: 'POST',
                uri: 'orders/{order}/cancel',
                summary: 'Отменить заказ (до передачи в сборку)',
                description: 'Доступно, пока заказ не ушёл в сборку; резервный заказ отменяется всегда. Заказ, переданный '
                    .'в сборку, отменяет только менеджер (422 not_cancellable). В 1С уходит order.deleted.',
                params: [Param::string('order', 'Заказ: id, номер или uuid', required: true)],
                handler: [self::class, 'cancel'],
                mutating: true,
                gate: FeatureGate::ORDER_CANCEL,
            ),
            new Operation(
                id: 'orders.repeat',
                section: 'orders',
                method: 'POST',
                uri: 'orders/{order}/repeat',
                summary: 'Повторить заказ — положить его позиции в корзину',
                description: 'mode=merge (по умолчанию) прибавляет к текущему количеству в активной корзине, '
                    .'mode=replace очищает её и кладёт только позиции заказа. Количества урезаются по доступному остатку. '
                    .'Оформление — checkout.submit.',
                params: [
                    Param::string('order', 'Заказ: id, номер или uuid', required: true),
                    Param::string('mode', 'merge — прибавить, replace — заменить состав корзины', enum: ['merge', 'replace']),
                ],
                handler: [self::class, 'repeat'],
                mutating: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $filters = [
            'status' => $input->array('status'),
            'type' => $input->string('type'),
            'company_id' => $input->has('company_id')
                ? $this->companies->filterIds($actor, $input->int('company_id'))[0]
                : null,
            'date_from' => $input->string('date_from'),
            'date_to' => $input->string('date_to'),
            'search' => $input->string('search'),
            'updated_since' => $input->string('updated_since'),
            // Курсору нужны колонки без выражений и тай-брейк по id.
            'sort_by' => 'created_at',
            'sort_order' => 'desc',
        ];

        $paginator = $this->orders
            ->builder($actor, $filters, preorders: null)
            ->cursorPaginate(Envelope::perPage($input->get('per_page')), ['*'], 'cursor', $input->string('cursor'));

        $this->presenter->primeFulfilment($paginator->items());

        return Envelope::cursor($paginator, fn (Order $order) => $this->presenter->row($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $actor, OperationInput $input): array
    {
        $order = $this->orderOf($actor, (string) $input->get('order'));

        $this->presenter->loadApiCard($order);

        return Envelope::data($this->presenter->card($order, $actor));
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(User $actor, OperationInput $input): array
    {
        $rows = $this->changes->rows($actor, [
            'type' => $input->string('type'),
            'date_from' => $input->string('date_from'),
            'date_to' => $input->string('date_to'),
        ]);

        $perPage = Envelope::perPage($input->get('per_page'), default: 100, max: Envelope::PER_PAGE_MAX_FEED);
        $page = max(1, $input->int('page', 1));
        $total = count($rows);

        $data = array_map(fn (array $r) => [
            'order_id' => $r['order_id'],
            'order_number' => $r['order_number'],
            'order_label' => $r['order_label'],
            'order_type' => $r['order_type'],
            'changed_at' => $r['changed_at']?->toIso8601String(),
            'kind' => $r['kind'],
            'type' => $r['type'],
            'type_label' => OrderChangeFeed::TYPE_LABELS[$r['type']] ?? $r['type'],
            'product_uuid' => $r['external_id'],
            'product_name' => $r['product_name'],
            'from' => $r['from'],
            'to' => $r['to'],
        ], OrderChangeFeed::slice($rows, $page, $perPage));

        return Envelope::data($data, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) max((int) ceil($total / $perPage), 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(User $actor, OperationInput $input): array
    {
        $company = $this->company($input);
        $reserve = $input->bool('reserve');

        // Резерв — только участнику режима: явный отказ, а не тихое игнорирование флага.
        if ($reserve && ! FeatureGate::RESERVE->allows($actor)) {
            throw new GateClosed(FeatureGate::RESERVE);
        }

        $products = [];

        foreach ($input->array('products') as $i => $row) {
            $row = is_array($row) ? $row : [];
            $identifier = trim((string) ($row['identifier'] ?? ''));
            $quantity = $row['quantity'] ?? null;

            if ($identifier === '') {
                throw ValidationException::withMessages(["products.{$i}.identifier" => 'Идентификатор товара обязателен']);
            }

            if (! is_numeric($quantity) || (int) $quantity < 1) {
                throw ValidationException::withMessages(["products.{$i}.quantity" => 'Количество должно быть не менее 1']);
            }

            $products[] = ['identifier' => $identifier, 'quantity' => (int) $quantity];
        }

        // Совсем нечего отгружать — NothingToPlaceException, контроллер отдаст
        // 422 nothing_to_place с перечнем причин; ключ идемпотентности освобождается.
        $result = $this->placement->place($actor, $company, new PlacementRequest(
            products: $products,
            deliveryMethod: DeliveryMethod::from($input->string('delivery_method') ?? DeliveryMethod::DELIVERY->value),
            address: $input->string('address'),
            comment: $input->string('comment'),
            applyPromotions: $input->bool('apply_promotions'),
            reserve: $reserve,
        ));

        $meta = [
            'created' => true,
            'total_orders' => count($result->orders),
            'fully_fulfilled' => $result->fullyFulfilled(),
            'not_accepted' => $result->notAccepted,
            'partial' => $result->partial,
        ];

        if ($result->promotions !== null) {
            $meta['promotions'] = $result->promotions->toResponse($result->orders);
        }

        // Правило выдачи самовывоза — и агенту тоже: курьер без пропуска ждёт проверки на складе
        if ((bool) config('pickup.enabled') && $input->string('delivery_method') === DeliveryMethod::PICKUP->value) {
            $meta['pickup_notice'] = 'Самовывоз: когда заказ соберут, придёт письмо и стадия «Собран». Курьера отправляйте с пропуском из кабинета (операции pickup.*): без пропуска склад выдаёт только после проверки и с подписью — дольше.';
        }

        return Envelope::data($result->orderRows(), $meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(User $actor, OperationInput $input): array
    {
        $order = $this->orderOf($actor, (string) $input->get('order'));

        $this->actions->cancel($order, $this->publisher, 'Отменён клиентом '.ClientApiSource::label());

        return Envelope::data([
            'order_id' => $order->id,
            'status' => $order->status?->value,
            'cancelled' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function repeat(User $actor, OperationInput $input): array
    {
        $order = $this->orderOf($actor, (string) $input->get('order'), withTrashed: true);

        return Envelope::data($this->repeater->repeat($actor, $order, $input->string('mode') ?? OrderRepeater::MODE_MERGE));
    }
}
