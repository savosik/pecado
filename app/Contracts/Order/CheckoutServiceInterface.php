<?php

namespace App\Contracts\Order;

use App\Models\Cart;
use App\Models\Company;
use Illuminate\Support\Collection;

interface CheckoutServiceInterface
{
    /**
     * Create order(s) from a cart, splitting by stock availability.
     * Returns a Collection of created Orders (1 or 2 depending on cart contents).
     *
     * @return Collection<int, \App\Models\Order>
     */
    public function checkout(
        Cart $cart,
        Company $company,
        string $deliveryAddress,
        ?string $comment = null
    ): Collection;
}
