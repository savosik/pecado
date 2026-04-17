<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Product;
use App\Services\Erp\Handlers\HandlePriceUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePriceUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_product_base_price(): void
    {
        $product = Product::factory()->create([
            'external_id' => '550e8400-e29b-41d4-a716-446655440000',
            'base_price' => 10000.00,
        ]);

        $handler = new HandlePriceUpdated;
        $handler->handle([
            'event' => 'price.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'price' => 15000.00,
        ]);

        $product->refresh();

        $this->assertEquals(15000.00, (float) $product->base_price);
    }

    #[Test]
    public function it_ignores_unknown_product_without_error(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'товар не найден по UUID');
            });

        $handler = new HandlePriceUpdated;
        $handler->handle([
            'event' => 'price.updated',
            'product_uuid' => 'nonexistent-uuid-1234',
            'price' => 15000.00,
        ]);

        // Не должно быть ошибки — просто игнорируем
    }

    #[Test]
    public function it_does_nothing_when_product_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует product_uuid или price');
            });

        $handler = new HandlePriceUpdated;
        $handler->handle([
            'event' => 'price.updated',
            'price' => 15000.00,
        ]);
    }

    #[Test]
    public function it_does_nothing_when_price_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует product_uuid или price');
            });

        $handler = new HandlePriceUpdated;
        $handler->handle([
            'event' => 'price.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);
    }

    #[Test]
    public function it_updates_price_to_zero(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'zero-price-uuid',
            'base_price' => 5000.00,
        ]);

        $handler = new HandlePriceUpdated;
        $handler->handle([
            'event' => 'price.updated',
            'product_uuid' => 'zero-price-uuid',
            'price' => 0,
        ]);

        $product->refresh();

        $this->assertEquals(0, (float) $product->base_price);
    }

    #[Test]
    public function it_overwrites_existing_price(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'overwrite-uuid',
            'base_price' => 10000.00,
        ]);

        $handler = new HandlePriceUpdated;

        // Первое обновление
        $handler->handle([
            'event' => 'price.updated',
            'product_uuid' => 'overwrite-uuid',
            'price' => 20000.00,
        ]);

        $product->refresh();
        $this->assertEquals(20000.00, (float) $product->base_price);

        // Второе обновление
        $handler->handle([
            'event' => 'price.updated',
            'product_uuid' => 'overwrite-uuid',
            'price' => 7500.50,
        ]);

        $product->refresh();
        $this->assertEquals(7500.50, (float) $product->base_price);
    }
}
