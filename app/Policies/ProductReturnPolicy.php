<?php

namespace App\Policies;

use App\Models\ProductReturn;
use App\Models\User;

class ProductReturnPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can view their own, Admin all (handled in query)
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ProductReturn $return): bool
    {
        return $user->is_admin || $user->id === $return->user_id;
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
    public function update(User $user, ProductReturn $return): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProductReturn $return): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ProductReturn $return): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ProductReturn $return): bool
    {
        return $user->is_admin;
    }
}
