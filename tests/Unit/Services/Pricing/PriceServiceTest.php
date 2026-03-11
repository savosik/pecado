<?php

namespace Tests\Unit\Services\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\PartnerSegment;
use App\Models\Product;
use App\Models\ProductSegment;
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
        $discount1 = Discount::create(['name' => 'D1', 'percentage' => 10, 'is_posted' => true, 'type' => 'agreement']);
        $discount1->users()->attach($user->id);
        $discount1->products()->attach($product->id);

        $discount2 = Discount::create(['name' => 'D2', 'percentage' => 30, 'is_posted' => true, 'type' => 'agreement']);
        $discount2->users()->attach($user->id);
        $discount2->products()->attach($product->id);

        // Inactive discount (should be ignored)
        $discount3 = Discount::create(['name' => 'D3', 'percentage' => 50, 'is_posted' => false, 'type' => 'agreement']);
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

    // -------------------------------------------------------------------
    // US-03 v2: Сегменты
    // -------------------------------------------------------------------

    public function test_it_applies_discount_when_partner_matched_via_segment(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Test', 'base_price' => 100.00]);

        // Сегмент партнёров содержит пользователя
        $partnerSegment = PartnerSegment::create(['uuid' => 'seg-part-test-01', 'name' => 'Тест']);
        $partnerSegment->users()->attach($user->id);

        // Скидка привязана к сегменту партнёра и к товару напрямую
        $discount = Discount::create(['type' => 'agreement', 'percentage' => 20, 'is_posted' => true]);
        $discount->partnerSegments()->attach($partnerSegment->id);
        $discount->products()->attach($product->id);

        $currencyResolver->shouldReceive('resolve')->andReturn(null);

        // Ожидаем: 100 * (1 - 20/100) = 80
        $this->assertEquals(80.00, $service->getDiscountedPrice($product, $user));
    }

    public function test_it_applies_discount_when_product_matched_via_segment(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Test', 'base_price' => 200.00]);

        // Сегмент номенклатуры содержит товар
        $productSegment = ProductSegment::create(['uuid' => 'seg-prod-test-01', 'name' => 'Тест']);
        $productSegment->products()->attach($product->id);

        // Скидка привязана к партнёру напрямую и к сегменту номенклатуры
        $discount = Discount::create(['type' => 'agreement', 'percentage' => 15, 'is_posted' => true]);
        $discount->users()->attach($user->id);
        $discount->productSegments()->attach($productSegment->id);

        $currencyResolver->shouldReceive('resolve')->andReturn(null);

        // Ожидаем: 200 * (1 - 15/100) = 170
        $this->assertEquals(170.00, $service->getDiscountedPrice($product, $user));
    }

    public function test_it_applies_discount_when_both_sides_matched_via_segments(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Test', 'base_price' => 500.00]);

        $partnerSegment = PartnerSegment::create(['uuid' => 'seg-part-test-02', 'name' => 'Голд']);
        $partnerSegment->users()->attach($user->id);

        $productSegment = ProductSegment::create(['uuid' => 'seg-prod-test-02', 'name' => 'Люкс']);
        $productSegment->products()->attach($product->id);

        // Скидка привязана через оба сегмента — ни прямых привязок нет
        $discount = Discount::create(['type' => 'agreement', 'percentage' => 25, 'is_posted' => true]);
        $discount->partnerSegments()->attach($partnerSegment->id);
        $discount->productSegments()->attach($productSegment->id);

        $currencyResolver->shouldReceive('resolve')->andReturn(null);

        // Ожидаем: 500 * (1 - 25/100) = 375
        $this->assertEquals(375.00, $service->getDiscountedPrice($product, $user));
    }

    public function test_expired_promotion_is_ignored(): void
    {
        $currencyService = Mockery::mock(CurrencyConversionServiceInterface::class);
        $currencyResolver = Mockery::mock(UserCurrencyResolverInterface::class);
        $service = new PriceService($currencyService, $currencyResolver);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Test', 'base_price' => 100.00]);

        // Акция с истёкшим сроком — не должна применяться
        $expired = Discount::create([
            'type' => 'promotion',
            'percentage' => 50,
            'is_posted' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
        $expired->users()->attach($user->id);
        $expired->products()->attach($product->id);

        $currencyResolver->shouldReceive('resolve')->andReturn(null);

        // Скидка истекла — цена должна остаться базовой
        $this->assertEquals(100.00, $service->getDiscountedPrice($product, $user));
    }
}
