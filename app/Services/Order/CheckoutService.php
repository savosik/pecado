<?php

namespace App\Services\Order;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Defect\DefectStockServiceInterface;
use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Defect\DefectPickListFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckoutService implements CheckoutServiceInterface
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected UserCurrencyResolverInterface $currencyResolver,
        protected StockServiceInterface $stockService,
        protected DefectStockServiceInterface $defectStockService,
        protected DefectPickListFormatter $defectPickListFormatter
    ) {}

    /**
     * Create order(s) from a cart, splitting by stock availability.
     * Returns a Collection of created Orders (1 or 2).
     *
     * @return Collection<int, Order>
     */
    public function checkout(
        Cart $cart,
        Company $company,
        ?string $deliveryAddress,
        ?string $comment = null,
        ?string $managerComment = null,
        ?string $warehouseComment = null,
        DeliveryMethod $deliveryMethod = DeliveryMethod::DELIVERY
    ): Collection {
        return DB::transaction(function () use ($cart, $company, $deliveryAddress, $comment, $managerComment, $warehouseComment, $deliveryMethod) {
            $user = $cart->user;
            $currency = $this->currencyResolver->resolve($user);

            $baseOrderData = [
                'user_id' => $user->id,
                'company_id' => $company->id,
                // При самовывозе адрес доставки не хранится
                'delivery_address' => $deliveryMethod === DeliveryMethod::PICKUP ? null : $deliveryAddress,
                'delivery_method' => $deliveryMethod,
                'cart_id' => $cart->id,
                'status' => \App\Enums\OrderStatus::PENDING_APPROVAL,
                'comment' => $comment,
                'manager_comment' => $managerComment ?: null,
                'warehouse_comment' => $warehouseComment ?: null,
                'total_amount' => 0,
                'exchange_rate' => $currency?->exchange_rate ?? 1.0,
                'rate_coefficient' => $currency?->rate_coefficient ?? 1.0,
                'currency_code' => $currency?->code ?? 'RUB',
            ];

            // Validate stock availability before proceeding
            $insufficientStockItems = [];
            foreach ($cart->items as $item) {
                if ($item->item_type === 'defect') {
                    // Уценка: лимит — свободный остаток конкретной партии, не остаток товара.
                    $defect = $item->productDefect;
                    $available = ($defect && $defect->is_published && $defect->price !== null && ! $defect->isClosed())
                        ? $this->defectStockService->available($defect)
                        : 0;

                    if ($item->quantity > $available) {
                        $insufficientStockItems[] = [
                            'cart_item_id' => $item->id,
                            'product_id' => $item->product->id,
                            'product' => $item->product->name,
                            'name' => $item->product->name,
                            'sku' => $item->product->sku,
                            'item_type' => 'defect',
                            'requested' => $item->quantity,
                            'available' => $available,
                        ];
                    }

                    continue;
                }

                $stock = $this->stockService->getStock($item->product, $user);
                $totalAvailable = $stock['available'] + $stock['preorder'];

                if ($item->quantity > $totalAvailable) {
                    $insufficientStockItems[] = [
                        'cart_item_id' => $item->id,
                        'product_id' => $item->product->id,
                        'product' => $item->product->name,
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'item_type' => $item->item_type,
                        'requested' => $item->quantity,
                        'available' => $totalAvailable,
                    ];
                }
            }

            if (! empty($insufficientStockItems)) {
                throw new \App\Exceptions\InsufficientStockException(
                    'Insufficient stock for some items',
                    $insufficientStockItems
                );
            }

            // Separate cart items by item_type (already split by CartService at add-to-cart time)
            $inStockCartItems = $cart->items->filter(fn ($item) => $item->item_type === 'instock')->values();
            $preorderCartItems = $cart->items->filter(fn ($item) => $item->item_type === 'preorder')->values();
            $defectCartItems = $cart->items->filter(fn ($item) => $item->item_type === 'defect')->values();

            $orders = collect();

            // Create instock order
            if ($inStockCartItems->isNotEmpty()) {
                $instockOrder = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::ORDER,
                ]));
                $total = $this->createOrderItems($instockOrder, $inStockCartItems, $user);
                $instockOrder->total_amount = $total;
                $instockOrder->saveQuietly();
                OrderCreated::dispatch($instockOrder);
                $orders->push($instockOrder);
            }

            // Create preorder order
            if ($preorderCartItems->isNotEmpty()) {
                $preorderOrder = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::PREORDER,
                ]));
                $total = $this->createOrderItems($preorderOrder, $preorderCartItems, $user);
                $preorderOrder->total_amount = $total;
                $preorderOrder->saveQuietly();
                OrderCreated::dispatch($preorderOrder);
                $orders->push($preorderOrder);
            }

            // Create defect (уценка) order — отдельный заказ со складом некондиции.
            // Остаток партии проверен выше в этой же транзакции. Параллельный
            // checkout той же партии может дать небольшой овербукинг — как и для
            // обычных товаров, окончательный остаток подтверждает 1С реализацией.
            if ($defectCartItems->isNotEmpty()) {
                // Кладовщик собирает по печатному документу 1С и в WMS заходит
                // редко — дописываем в комментарий склада конкретику по каждой
                // партии брака (артикул, id партии, дефекты, количество).
                $defectCartItems->load('product', 'productDefect');
                $pickList = $this->defectPickListFormatter->format($defectCartItems);
                $defectWarehouseComment = trim(
                    ($warehouseComment ? $warehouseComment."\n\n" : '').$pickList
                );

                $defectOrder = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::DEFECT,
                    'warehouse_comment' => $defectWarehouseComment ?: null,
                ]));
                $total = $this->createDefectOrderItems($defectOrder, $defectCartItems);
                $defectOrder->total_amount = $total;
                $defectOrder->saveQuietly();
                OrderCreated::dispatch($defectOrder);
                $orders->push($defectOrder);
            }

            return $orders;
        });
    }

    /**
     * Create order items for a given order from cart items.
     */
    protected function createOrderItems(Order $order, Collection $cartItems, $user): float
    {
        $total = 0;
        foreach ($cartItems as $item) {
            $priceResult = $this->priceService->getPriceResult($item->product, $user);
            $displayPrice = $priceResult->getDisplayPrice();
            $subtotal = $displayPrice * $item->quantity;
            $total += $subtotal;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'price' => $displayPrice,
                'base_price' => $priceResult->basePrice,
                'discount_percent' => $priceResult->discountPercent,
                'final_price' => $displayPrice,
                'quantity' => $item->quantity,
                'subtotal' => $subtotal,
            ]);
        }

        return $total;
    }

    /**
     * Позиции заказа уценки.
     *
     * Цена фиксирована в строке корзины (= цена партии), скидки и индивидуальные
     * цены к ней не применяются. Дублируем описание дефекта снапшотом: партию
     * могут отредактировать позже, а в заказе должно остаться то, что видел клиент.
     */
    protected function createDefectOrderItems(Order $order, Collection $cartItems): float
    {
        $total = 0;
        foreach ($cartItems as $item) {
            $price = (float) ($item->price ?? 0);
            $subtotal = $price * $item->quantity;
            $total += $subtotal;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'product_defect_id' => $item->product_defect_id,
                'defect_description' => $item->productDefect?->defect_description,
                'name' => $item->product->name,
                'price' => $price,
                'base_price' => $price,
                'discount_percent' => 0,
                'final_price' => $price,
                'quantity' => $item->quantity,
                'subtotal' => $subtotal,
            ]);
        }

        return $total;
    }
}
