<?php

namespace App\Policies;

use App\Models\WishlistItem;
use App\Models\User;

class WishlistItemPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Users can view their own wishlist items, admins can view all
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WishlistItem $wishlistItem): bool
    {
        return $user->is_admin || $user->id === $wishlistItem->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WishlistItem $wishlistItem): bool
    {
        return $user->is_admin || $user->id === $wishlistItem->user_id;
    }
}
