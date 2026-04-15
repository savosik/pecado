<?php

namespace App\Services\Order;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckoutService implements CheckoutServiceInterface
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected UserCurrencyResolverInterface $currencyResolver,
        protected StockServiceInterface $stockService
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
        string $deliveryAddress,
        ?string $comment = null
    ): Collection {
        return DB::transaction(function () use ($cart, $company, $deliveryAddress, $comment) {
            $user = $cart->user;
            $currency = $this->currencyResolver->resolve($user);

            $baseOrderData = [
                'user_id'             => $user->id,
                'company_id'          => $company->id,
                'delivery_address'    => $deliveryAddress,
                'cart_id'             => $cart->id,
                'status'              => 'pending',
                'comment'             => $comment,
                'total_amount'        => 0,
                'exchange_rate'       => $currency?->exchange_rate ?? 1.0,
                'rate_coefficient'    => $currency?->rate_coefficient ?? 1.0,
                'currency_code'       => $currency?->code ?? 'RUB',
            ];

            // Validate stock availability before proceeding
            $insufficientStockItems = [];
            foreach ($cart->items as $item) {
                $stock = $this->stockService->getStock($item->product, $user);
                $totalAvailable = $stock['available'] + $stock['preorder'];

                if ($item->quantity > $totalAvailable) {
                    $insufficientStockItems[] = [
                        'product'   => $item->product->name,
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

            // Separate cart items by item_type (already split by CartService at add-to-cart time)
            $inStockCartItems = $cart->items->filter(fn($item) => $item->item_type === 'instock')->values();
            $preorderCartItems = $cart->items->filter(fn($item) => $item->item_type === 'preorder')->values();

            $orders = collect();

            // Create instock order
            if ($inStockCartItems->isNotEmpty()) {
                $instockOrder = Order::create(array_merge($baseOrderData, [
                    'type' => 'order',
                ]));
                $total = $this->createOrderItems($instockOrder, $inStockCartItems, $user);
                $instockOrder->update(['total_amount' => $total]);
                OrderCreated::dispatch($instockOrder);
                $orders->push($instockOrder);
            }

            // Create preorder order
            if ($preorderCartItems->isNotEmpty()) {
                $preorderOrder = Order::create(array_merge($baseOrderData, [
                    'type' => 'preorder',
                ]));
                $total = $this->createOrderItems($preorderOrder, $preorderCartItems, $user);
                $preorderOrder->update(['total_amount' => $total]);
                OrderCreated::dispatch($preorderOrder);
                $orders->push($preorderOrder);
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
            $total   += $subtotal;

            OrderItem::create([
                'order_id'         => $order->id,
                'product_id'       => $item->product_id,
                'name'             => $item->product->name,
                'price'            => $displayPrice,
                'base_price'       => $priceResult->basePrice,
                'discount_percent' => $priceResult->discountPercent,
                'final_price'      => $displayPrice,
                'quantity'         => $item->quantity,
                'subtotal'         => $subtotal,
            ]);
        }
        return $total;
    }
}
