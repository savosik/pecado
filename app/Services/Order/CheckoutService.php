<?php

namespace App\Services\Order;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class CheckoutService implements CheckoutServiceInterface
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected UserCurrencyResolverInterface $currencyResolver,
        protected StockServiceInterface $stockService
    ) {}

    /**
     * Create an order from a cart, splitting by stock availability.
     */
    public function checkout(
        Cart $cart,
        Company $company,
        DeliveryAddress $address,
        ?string $comment = null
    ): Order {
        return DB::transaction(function () use ($cart, $company, $address, $comment) {
            $user = $cart->user;
            $currency = $this->currencyResolver->resolve($user);

            $baseOrderData = [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'delivery_address_id' => $address->id,
                'cart_id' => $cart->id,
                'status' => \App\Enums\OrderStatus::PENDING,
                'comment' => $comment,
                'total_amount' => 0,
                'exchange_rate' => $currency?->exchange_rate ?? 1.0,
                'correction_factor' => $currency?->correction_factor ?? 1.0,
                'currency_code' => $currency?->code ?? 'RUB',
            ];

            // Create parent order
            $parentOrder = Order::create(array_merge($baseOrderData, [
                'type' => OrderType::STANDARD,
            ]));

            // Validate stock availability before proceeding
            $insufficientStockItems = [];
            foreach ($cart->items as $item) {
                $stock = $this->stockService->getStock($item->product, $user);
                $totalAvailable = $stock['available'] + $stock['preorder'];
                
                if ($item->quantity > $totalAvailable) {
                    $insufficientStockItems[] = [
                        'product' => $item->product->name,
                        'requested' => $item->quantity,
                        'available' => $totalAvailable,
                    ];
                }
            }

            if (!empty($insufficientStockItems)) {
                throw new \App\Exceptions\InsufficientStockException(
                    'Insufficient stock for some items',
                    $insufficientStockItems
                );
            }

            // Separate items by stock availability
            $inStockItems = [];
            $preorderItems = [];

            foreach ($cart->items as $item) {
                $stock = $this->stockService->getStock($item->product, $user);
                $availableQty = $stock['available'];
                $preorderQty = $stock['preorder'];

                $requestedQty = $item->quantity;

                // Allocate to in-stock first
                $inStockAlloc = min($requestedQty, $availableQty);
                if ($inStockAlloc > 0) {
                    $inStockItems[] = [
                        'item' => $item,
                        'quantity' => $inStockAlloc,
                    ];
                }

                // Remaining goes to preorder
                $remainingQty = $requestedQty - $inStockAlloc;
                if ($remainingQty > 0 && $preorderQty > 0) {
                    $preorderAlloc = min($remainingQty, $preorderQty);
                    $preorderItems[] = [
                        'item' => $item,
                        'quantity' => $preorderAlloc,
                    ];
                }
            }

            $parentTotal = 0;

            // Create in-stock child order
            if (!empty($inStockItems)) {
                $childOrder = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::IN_STOCK,
                    'parent_id' => $parentOrder->id,
                ]));
                $childTotal = $this->createOrderItems($childOrder, $inStockItems, $user);
                $childOrder->update(['total_amount' => $childTotal]);
                $parentTotal += $childTotal;
            }

            // Create preorder child order
            if (!empty($preorderItems)) {
                $childOrder = Order::create(array_merge($baseOrderData, [
                    'type' => OrderType::PREORDER,
                    'parent_id' => $parentOrder->id,
                ]));
                $childTotal = $this->createOrderItems($childOrder, $preorderItems, $user);
                $childOrder->update(['total_amount' => $childTotal]);
                $parentTotal += $childTotal;
            }

            // Update parent total
            $parentOrder->update(['total_amount' => $parentTotal]);

            return $parentOrder;
        });
    }

    /**
     * Create order items for a given order.
     */
    protected function createOrderItems(Order $order, array $allocatedItems, $user): float
    {
        $total = 0;
        foreach ($allocatedItems as $alloc) {
            $item = $alloc['item'];
            $quantity = $alloc['quantity'];

            $price = $this->priceService->getDiscountedPrice($item->product, $user);
            $subtotal = $price * $quantity;
            $total += $subtotal;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'price' => $price,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
            ]);
        }
        return $total;
    }
}
