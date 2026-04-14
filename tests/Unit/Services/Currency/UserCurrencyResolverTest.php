<?php

namespace Tests\Unit\Services\Currency;

use App\Models\Currency;
use App\Models\Region;
use App\Models\User;
use App\Services\Currency\UserCurrencyResolver;
use Tests\TestCase;

class UserCurrencyResolverTest extends TestCase
{
    public function test_it_resolves_user_currency_through_region()
    {
        $service = new UserCurrencyResolver();

        $currency = new Currency(['id' => 1, 'code' => 'KZT']);
        $region = new Region();
        $region->setRelation('currency', $currency);

        $user = new User();
        $user->setRelation('region', $region);

        $resolved = $service->resolve($user);

        $this->assertSame($currency, $resolved);
    }

    public function test_it_returns_null_if_no_region()
    {
        $service = new UserCurrencyResolver();
        $user = new User();

        $resolved = $service->resolve($user);

        $this->assertNull($resolved);
    }

    public function test_it_returns_null_if_region_has_no_currency()
    {
        $service = new UserCurrencyResolver();

        $region = new Region();
        $region->setRelation('currency', null);

        $user = new User();
        $user->setRelation('region', $region);

        $resolved = $service->resolve($user);

        $this->assertNull($resolved);
    }
}
