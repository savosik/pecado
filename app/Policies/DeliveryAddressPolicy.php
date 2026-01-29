<?php

namespace App\Policies;

use App\Models\DeliveryAddress;
use App\Models\User;

class DeliveryAddressPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Users can view their own addresses, admins can view all
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DeliveryAddress $deliveryAddress): bool
    {
        return $user->is_admin || $user->id === $deliveryAddress->user_id;
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
    public function update(User $user, DeliveryAddress $deliveryAddress): bool
    {
        return $user->is_admin || $user->id === $deliveryAddress->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DeliveryAddress $deliveryAddress): bool
    {
        return $user->is_admin || $user->id === $deliveryAddress->user_id;
    }
}
