<?php

namespace App\Policies;

use App\Models\Cart;
use App\Models\User;

class CartPolicy
{
    /**
     * Determine whether the user can view the cart.
     */
    public function view(User $user, Cart $cart): bool
    {
        return $cart->user_id === $user->id;
    }

    /**
     * Determine whether the user can create carts.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the cart.
     */
    public function update(User $user, Cart $cart): bool
    {
        return $cart->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the cart.
     */
    public function delete(User $user, Cart $cart): bool
    {
        return $cart->user_id === $user->id;
    }
}
