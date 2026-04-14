<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Region;
use App\Models\User;
use App\Services\Currency\UserCurrencyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyRegionResolverTest extends TestCase
{
    use RefreshDatabase;

    private UserCurrencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new UserCurrencyResolver();
    }

    public function test_resolves_currency_through_region(): void
    {
        $currency = Currency::factory()->create(['code' => 'RUB', 'is_base' => true]);
        $region = Region::factory()->create(['currency_id' => $currency->id]);
        $user = User::factory()->create(['region_id' => $region->id]);

        $resolved = $this->resolver->resolve($user);

        $this->assertNotNull($resolved);
        $this->assertEquals('RUB', $resolved->code);
        $this->assertEquals($currency->id, $resolved->id);
    }

    public function test_returns_null_when_user_has_no_region(): void
    {
        $user = User::factory()->create(['region_id' => null]);

        $resolved = $this->resolver->resolve($user);

        $this->assertNull($resolved);
    }

    public function test_returns_null_when_region_has_no_currency(): void
    {
        $region = Region::factory()->create(['currency_id' => null]);
        $user = User::factory()->create(['region_id' => $region->id]);

        $resolved = $this->resolver->resolve($user);

        $this->assertNull($resolved);
    }

    public function test_resolves_non_base_currency(): void
    {
        $byn = Currency::factory()->create([
            'code' => 'BYN',
            'is_base' => false,
            'exchange_rate' => 3.5,
        ]);
        $region = Region::factory()->create(['currency_id' => $byn->id]);
        $user = User::factory()->create(['region_id' => $region->id]);

        $resolved = $this->resolver->resolve($user);

        $this->assertNotNull($resolved);
        $this->assertEquals('BYN', $resolved->code);
        $this->assertFalse($resolved->is_base);
    }

    public function test_resolved_currency_accessor_on_user_model(): void
    {
        $currency = Currency::factory()->create(['code' => 'RUB', 'is_base' => true]);
        $region = Region::factory()->create(['currency_id' => $currency->id]);
        $user = User::factory()->create(['region_id' => $region->id]);

        $this->assertEquals('RUB', $user->resolved_currency->code);
    }

    public function test_resolved_currency_accessor_null_without_region(): void
    {
        $user = User::factory()->create(['region_id' => null]);

        $this->assertNull($user->resolved_currency);
    }
}
