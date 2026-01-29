<?php

namespace App\Contracts\Currency;

use App\Models\Currency;
use App\Models\User;

interface UserCurrencyResolverInterface
{
    /**
     * Resolve the preferred currency for a given user.
     *
     * @param User $user
     * @return Currency|null Returns the currency if resolved, otherwise null.
     */
    public function resolve(User $user): ?Currency;
}
