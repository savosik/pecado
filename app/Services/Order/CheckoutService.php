<?php

namespace App\Services\Order;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
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
        protected UserCurrencyResolverInterface $currencyResolver
    ) {}

    /**
     * Create an order from a cart.
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

            // Create the order
            $order = Order::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'delivery_address_id' => $address->id,
                'cart_id' => $cart->id,
                'status' => \App\Enums\OrderStatus::PENDING,
                'comment' => $comment,
                'total_amount' => 0, // Will be updated
                'exchange_rate' => $currency?->exchange_rate ?? 1.0,
                'correction_factor' => $currency?->correction_factor ?? 1.0,
                'currency_code' => $currency?->code ?? 'RUB',
            ]);

            $totalAmount = 0;

            // Convert cart items to order items
            foreach ($cart->items as $item) {
                // Fix price in base currency with discounts
                $price = $this->priceService->getDiscountedPrice($item->product, $user);
                $subtotal = $price * $item->quantity;
                $totalAmount += $subtotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product->name,
                    'price' => $price,
                    'quantity' => $item->quantity,
                    'subtotal' => $subtotal,
                ]);
            }

            // Update total amount
            $order->update(['total_amount' => $totalAmount]);

            return $order;
        });
    }
}
