<?php

namespace Tests\Unit\Services\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Currency;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // Expect conversion call (no individual price, so base price)
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

    // -------------------------------------------------------------------
    // v7: Individual Prices
    // -------------------------------------------------------------------

    public function test_it_returns_individual_price_when_available(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create(['erp_id' => 'partner-uuid-001']);
        $product = Product::create([
            'name' => 'Test',
            'base_price' => 100.00,
            'external_id' => 'product-uuid-001',
        ]);

        // Создаём индивидуальную цену
        IndividualPrice::create([
            'partner_uuid' => 'partner-uuid-001',
            'product_uuid' => 'product-uuid-001',
            'warehouse_uuid' => 'wh-001',
            'price' => 70.00,
        ]);

        $currencyResolver->shouldReceive('resolve')->andReturn(null);

        // Ожидаем индивидуальную цену вместо базовой
        $this->assertEquals(70.00, $service->getUserPrice($product, $user));
    }

    public function test_it_returns_base_price_when_no_individual_price(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create(['erp_id' => 'partner-uuid-002']);
        $product = Product::create([
            'name' => 'Test',
            'base_price' => 200.00,
            'external_id' => 'product-uuid-002',
        ]);

        $currencyResolver->shouldReceive('resolve')->andReturn(null);

        // Нет индивидуальной цены — возвращаем базовую
        $this->assertEquals(200.00, $service->getUserPrice($product, $user));
    }

    public function test_get_price_result_returns_discount_info(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create(['erp_id' => 'partner-uuid-003']);
        $product = Product::create([
            'name' => 'Test',
            'base_price' => 100.00,
            'external_id' => 'product-uuid-003',
        ]);

        IndividualPrice::create([
            'partner_uuid' => 'partner-uuid-003',
            'product_uuid' => 'product-uuid-003',
            'warehouse_uuid' => 'wh-001',
            'price' => 70.00,
        ]);

        $result = $service->getPriceResult($product, $user);

        $this->assertEquals(100.00, $result->basePrice);
        $this->assertEquals(70.00, $result->individualPrice);
        $this->assertEquals(30.00, $result->discountPercent);
        $this->assertTrue($result->hasDiscount);
        $this->assertEquals(70.00, $result->getDisplayPrice());
    }

    public function test_get_price_result_without_discount(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create(['erp_id' => 'partner-uuid-004']);
        $product = Product::create([
            'name' => 'Test',
            'base_price' => 100.00,
            'external_id' => 'product-uuid-004',
        ]);

        $result = $service->getPriceResult($product, $user);

        $this->assertEquals(100.00, $result->basePrice);
        $this->assertNull($result->individualPrice);
        $this->assertEquals(0, $result->discountPercent);
        $this->assertFalse($result->hasDiscount);
        $this->assertEquals(100.00, $result->getDisplayPrice());
    }

    public function test_get_price_result_for_guest_user(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $product = Product::create([
            'name' => 'Test',
            'base_price' => 100.00,
            'external_id' => 'product-uuid-005',
        ]);

        $result = $service->getPriceResult($product, null);

        $this->assertEquals(100.00, $result->basePrice);
        $this->assertNull($result->individualPrice);
        $this->assertFalse($result->hasDiscount);
    }

    public function test_price_result_to_array(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create(['erp_id' => 'partner-uuid-006']);
        $product = Product::create([
            'name' => 'Test',
            'base_price' => 200.00,
            'external_id' => 'product-uuid-006',
        ]);

        IndividualPrice::create([
            'partner_uuid' => 'partner-uuid-006',
            'product_uuid' => 'product-uuid-006',
            'warehouse_uuid' => 'wh-001',
            'price' => 160.00,
        ]);

        $result = $service->getPriceResult($product, $user);
        $array = $result->toArray();

        $this->assertEquals(200.00, $array['base_price']);
        $this->assertEquals(160.00, $array['individual_price']);
        $this->assertEquals(20.00, $array['discount_percent']);
        $this->assertTrue($array['has_discount']);
        $this->assertEquals(160.00, $array['display_price']);
    }
}
