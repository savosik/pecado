<?php

namespace App\Contracts\Cart;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface CartServiceInterface
{
    /**
     * Get summary information for a cart.
     *
     * @return array{total_price: float, items_count: int, available_count: int, preorder_count: int}
     */
    public function getCartSummary(Cart $cart, User $user): array;

    /**
     * Get summary information for multiple carts.
     *
     * @param Collection<int, Cart> $carts
     * @return array<int, array{total_price: float, items_count: int, available_count: int, preorder_count: int}>
     */
    public function getCartsSummary(Collection $carts, User $user): array;
}
