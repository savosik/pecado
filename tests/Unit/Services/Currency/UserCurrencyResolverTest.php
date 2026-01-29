<?php

namespace Tests\Unit\Services\Currency;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Models\Currency;
use App\Models\User;
use App\Services\Currency\UserCurrencyResolver;
use Tests\TestCase;

class UserCurrencyResolverTest extends TestCase
{
    public function test_it_resolves_user_currency()
    {
        $service = new UserCurrencyResolver();

        $currency = new Currency(['id' => 1, 'code' => 'KZT']);
        $user = new User();
        $user->setRelation('currency', $currency);
        $user->currency_id = 1;

        $resolved = $service->resolve($user);

        $this->assertSame($currency, $resolved);
    }

    public function test_it_returns_null_if_no_currency()
    {
        $service = new UserCurrencyResolver();
        $user = new User();

        $resolved = $service->resolve($user);

        $this->assertNull($resolved);
    }
}
