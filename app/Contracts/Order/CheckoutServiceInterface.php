<?php

namespace App\Contracts\Order;

use App\Models\Cart;
use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\User;

interface CheckoutServiceInterface
{
    /**
     * Create an order from a cart.
     */
    public function checkout(
        Cart $cart,
        Company $company,
        DeliveryAddress $address,
        ?string $comment = null
    ): Order;
}
