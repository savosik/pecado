<?php

namespace Tests\Unit\Services\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PriceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_resolve_price_service()
    {
        $service = app(PriceServiceInterface::class);
        $this->assertInstanceOf(PriceService::class, $service);
    }

    public function test_it_returns_base_price()
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $product = new Product(['base_price' => 100.00]);

        $this->assertEquals(100.00, $service->getBasePrice($product));
    }

    public function test_it_returns_user_price_in_preferred_currency()
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $currency = new Currency(['id' => 1, 'code' => 'KZT']);
        $user = User::factory()->create();
        $product = Product::create(['name' => 'Test', 'base_price' => 100.00]);

        // Mock Resolver
        $currencyResolver->shouldReceive('resolve')
            ->with(Mockery::on(fn($u) => $u->id === $user->id))
            ->once()
            ->andReturn($currency);

        // Expect conversion call (no discount, so base price)
        $currencyService->shouldReceive('convertFromBase')
            ->with(100.00, $currency)
            ->once()
            ->andReturn(500.00);

        $this->assertEquals(500.00, $service->getUserPrice($product, $user));
    }

    public function test_it_returns_base_price_if_user_has_no_currency()
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Test', 'base_price' => 100.00]);

        // Mock Resolver returning null
        $currencyResolver->shouldReceive('resolve')
            ->with(Mockery::on(fn($u) => $u->id === $user->id))
            ->once()
            ->andReturn(null);

        $this->assertEquals(100.00, $service->getUserPrice($product, $user));
    }

    public function test_it_applies_max_discount_to_user_price()
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Test', 'base_price' => 100.00]);

        // Create active discounts
        $discount1 = Discount::create(['name' => 'D1', 'percentage' => 10, 'is_posted' => true]);
        $discount1->users()->attach($user->id);
        $discount1->products()->attach($product->id);

        $discount2 = Discount::create(['name' => 'D2', 'percentage' => 30, 'is_posted' => true]);
        $discount2->users()->attach($user->id);
        $discount2->products()->attach($product->id);

        // Inactive discount (should be ignored)
        $discount3 = Discount::create(['name' => 'D3', 'percentage' => 50, 'is_posted' => false]);
        $discount3->users()->attach($user->id);
        $discount3->products()->attach($product->id);

        // Mock Resolver returning null (no currency conversion)
        $currencyResolver->shouldReceive('resolve')
            ->with(Mockery::on(fn($u) => $u->id === $user->id))
            ->once()
            ->andReturn(null);

        // Expected: 100 * (1 - 30/100) = 70
        $this->assertEquals(70.00, $service->getUserPrice($product, $user));
    }
}
