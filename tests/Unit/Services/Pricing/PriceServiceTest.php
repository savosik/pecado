<?php

namespace Tests\Unit\Services\Pricing;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceService;
use Tests\TestCase;

class PriceServiceTest extends TestCase
{
    public function test_it_can_resolve_price_service()
    {
        $service = app(PriceServiceInterface::class);
        $this->assertInstanceOf(PriceService::class, $service);
    }

    public function test_it_returns_base_price()
    {
        $product = new Product(['base_price' => 100.00]);
        $service = app(PriceServiceInterface::class);

        $this->assertEquals(100.00, $service->getBasePrice($product));
    }

    public function test_it_returns_user_price_same_as_base_price_by_default()
    {
        $product = new Product(['base_price' => 200.00]);
        $user = new User();
        $service = app(PriceServiceInterface::class);

        $this->assertEquals(200.00, $service->getUserPrice($product, $user));
    }
}
