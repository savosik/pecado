<?php

namespace App\Services\Currency;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Models\Currency;
use App\Models\User;

class UserCurrencyResolver implements UserCurrencyResolverInterface
{
    /**
     * Resolve the preferred currency for a given user.
     */
    public function resolve(User $user): ?Currency
    {
        if ($user->currency_id) {
            return $user->currency;
        }

        return null;
    }
}
