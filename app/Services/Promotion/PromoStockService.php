<?php

namespace App\Services\Promotion;

use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Contracts\Promotion\PromoStockServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Фонд промо-позиций.
 *
 * Идеология скопирована с `DefectStockService`: резерв — производная величина,
 * а не поле. Он считается по `order_items` незакрытых промо-заказов, поэтому
 * удаление заказа само возвращает товар в фонд, и нет риска рассинхрона
 * денормализованного счётчика.
 *
 * ## Почему резерв считается по товару, а не по складу
 *
 * Карточка promo-07 задавала `available(товар, склад)` с резервом «по заказам
 * с этого склада». Схема так не умеет: ни `orders`, ни `order_items` не хранят
 * склад — `warehouse_uuids` вычисляется в момент публикации из типа заказа
 * и региона пользователя (`PublishOrderToErp::resolveWarehouseUuids()`).
 * Привязать резерв к складу нечем.
 *
 * Поэтому сигнатура повторяет `StockService::getStock(Product, ?User)`: остаток
 * берётся по primary-складам региона пользователя, а резерв — по товару целиком,
 * без разбивки. Это консервативнее: промо-заказ в другом регионе уменьшит
 * доступность здесь. Ошибка уходит в безопасную сторону — недодать промо-позицию
 * лучше, чем пообещать её клиенту и не выдать.
 *
 * ## Овербукинг
 *
 * Подотчётная промо-позиция лежит на обычном складе региона и параллельно
 * продаётся: витринное наличие про промо-резерв не знает. Принят тот же
 * компромисс, что и для уценки — окончательный остаток подтверждает 1С
 * реализацией. Вычитать промо-резерв из `StockService` **не нужно**: это горячий
 * путь всего каталога, и цена такой связки выше цены редкого овербукинга.
 *
 * ## Кэша нет
 *
 * Остатки едут по шине постоянно, а цена ошибки — обещанная и не выданная
 * промо-позиция.
 */
class PromoStockService implements PromoStockCheckerInterface, PromoStockServiceInterface
{
    /**
     * Типы заказов, которые держат резерв.
     */
    public const RESERVING_ORDER_TYPES = [OrderType::PROMO, OrderType::PROMO_SAMPLE];

    /**
     * Статусы, в которых промо-заказ ещё держит товар.
     *
     * Перечислены явно, а не как «все кроме закрытого»: при появлении нового
     * статуса решение о резерве должен принять человек, а не `match` по умолчанию.
     * Закрытый заказ товар не держит — он отгружен, и остаток уже уменьшила 1С.
     */
    public const RESERVING_STATUSES = [
        OrderStatus::PENDING_APPROVAL,
        OrderStatus::PENDING_PAYMENT_BEFORE_PROVISION,
        OrderStatus::READY_FOR_PROVISION,
        OrderStatus::PENDING_PAYMENT_BEFORE_SHIPMENT,
        OrderStatus::AWAITING_PROVISION,
        OrderStatus::READY_FOR_SHIPMENT,
        OrderStatus::SHIPPING,
        OrderStatus::AWAITING_PAYMENT,
        OrderStatus::READY_FOR_CLOSURE,
    ];

    /**
     * Снимок доступности на одно вычисление: «товар:склад:клиент» → количество.
     *
     * @var array<string, int>
     */
    private array $snapshot = [];

    public function __construct(private readonly StockServiceInterface $stockService) {}

    public function available(Product $product, ?User $user = null): int
    {
        return $this->availableMap([$product], $user)[$product->id] ?? 0;
    }

    /**
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function availableMap(iterable $products, ?User $user = null): array
    {
        $products = is_array($products) ? $products : iterator_to_array($products);

        if ($products === []) {
            return [];
        }

        // Два запроса на остаток (склады региона + pivot) и один на резерв —
        // независимо от числа товаров
        $stock = $this->stockService->getAvailableStockMap($products, $user);
        $reserved = $this->reservedMap(array_keys($stock));

        $result = [];
        foreach ($stock as $productId => $quantity) {
            $result[$productId] = max(0, $quantity - ($reserved[$productId] ?? 0));
        }

        return $result;
    }

    public function reserved(Product $product): int
    {
        return $this->reservedMap([(int) $product->id])[(int) $product->id] ?? 0;
    }

    public function isAvailable(int $productId, ?int $warehouseId, int $quantity, ?int $userId = null): bool
    {
        return $this->availableFor($productId, $warehouseId, $userId) >= $quantity;
    }

    /**
     * Сколько единиц можно выдать под конкретную награду.
     *
     * Движок спрашивает это по каждой награде подряд, поэтому ответы копятся
     * в снимке `$snapshot`. Это **не кэш**: снимок живёт внутри одного
     * вычисления и не переживает запрос. Помимо экономии запросов он даёт
     * важное свойство — все награды судятся по одному состоянию склада,
     * а не по номерам, которые уехали между первой и десятой проверкой.
     */
    public function availableFor(int $productId, ?int $warehouseId, ?int $userId = null): int
    {
        $key = $productId.':'.($warehouseId ?? '-').':'.($userId ?? '-');

        if (array_key_exists($key, $this->snapshot)) {
            return $this->snapshot[$key];
        }

        $product = Product::find($productId);

        if ($product === null) {
            return $this->snapshot[$key] = 0;
        }

        $stock = $warehouseId !== null
            // Награда прибита к конкретному складу — регион не участвует
            ? (int) DB::table('product_warehouse')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->sum('quantity')
            : $this->stockService->getAvailableStockMap([$product], $userId !== null ? User::find($userId) : null)[$productId] ?? 0;

        $reserved = $this->reservedMap([$productId])[$productId] ?? 0;

        return $this->snapshot[$key] = max(0, $stock - $reserved);
    }

    /**
     * Сбросить снимок доступности.
     *
     * Вызывает движок перед вычислением, чтобы новый рендер корзины не судил
     * по числам предыдущего.
     */
    public function forgetSnapshot(): void
    {
        $this->snapshot = [];
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function reservedMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $productIds)
            ->whereIn('orders.type', array_map(
                static fn (OrderType $type) => $type->value,
                self::RESERVING_ORDER_TYPES,
            ))
            ->whereIn('orders.status', array_map(
                static fn (OrderStatus $status) => $status->value,
                self::RESERVING_STATUSES,
            ))
            ->whereNull('orders.deleted_at')
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as total'))
            ->groupBy('order_items.product_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->product_id] = (int) $row->total;
        }

        return $result;
    }
}
