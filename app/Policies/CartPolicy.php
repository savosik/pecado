<?php

namespace App\Policies;

use App\Models\Cart;
use App\Models\User;

class CartPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Users can view their own carts, admins can view all
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Cart $cart): bool
    {
        return $user->is_admin || $user->id === $cart->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Cart $cart): bool
    {
        return $user->is_admin || $user->id === $cart->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Cart $cart): bool
    {
        return $user->is_admin || $user->id === $cart->user_id;
    }

    /**
     * Determine whether the user can add an item to the cart.
     */
    public function addItem(User $user, Cart $cart): bool
    {
        return $user->is_admin || $user->id === $cart->user_id;
    }

    /**
     * Determine whether the user can remove an item from the cart.
     */
    public function removeItem(User $user, Cart $cart): bool
    {
        return $user->is_admin || $user->id === $cart->user_id;
    }

    /**
     * Determine whether the user can update an item in the cart.
     */
    public function updateItem(User $user, Cart $cart): bool
    {
        return $user->is_admin || $user->id === $cart->user_id;
    }
}
