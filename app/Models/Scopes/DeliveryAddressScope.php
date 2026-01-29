<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class DeliveryAddressScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     * 
     * Limits delivery addresses to those owned by the current user,
     * unless the user is an admin.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && !$user->is_admin) {
            $builder->where('user_id', $user->id);
        }
    }
}
