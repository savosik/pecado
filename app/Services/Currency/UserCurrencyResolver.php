<?php

namespace App\Services\Currency;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Models\Currency;
use App\Models\User;

class UserCurrencyResolver implements UserCurrencyResolverInterface
{
    /**
     * Resolve the preferred currency for a given user.
     *
     * Валюта определяется через регион пользователя: User → Region → Currency.
     * Пользователь не может переключать валюту вручную.
     */
    public function resolve(User $user): ?Currency
    {
        return $user->region?->currency;
    }
}
