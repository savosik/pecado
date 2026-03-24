<?php

namespace App\Contracts\Order;

use App\Models\Cart;
use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\Order;
use Illuminate\Support\Collection;

interface CheckoutServiceInterface
{
    /**
     * Create order(s) from a cart, splitting by stock availability.
     * Returns a Collection of created Orders (1 or 2 depending on cart contents).
     *
     * @return Collection<int, Order>
     */
    public function checkout(
        Cart $cart,
        Company $company,
        DeliveryAddress $address,
        ?string $comment = null
    ): Collection;
}
