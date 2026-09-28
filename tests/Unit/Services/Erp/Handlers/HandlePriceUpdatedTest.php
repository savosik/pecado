<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\ErpPendingPrice;
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
    public function it_parks_price_for_unknown_product(): void
    {
        // v16.12.3: цена, обогнавшая product.created, не выбрасывается, а откладывается.
        $handler = new HandlePriceUpdated;
        $handler->handle([
            'event' => 'price.updated',
            'message_id' => 'msg-early-price',
            'product_uuid' => 'nonexistent-uuid-1234',
            'price' => 15000.00,
        ]);

        $pending = ErpPendingPrice::where('product_uuid', 'nonexistent-uuid-1234')->first();
        $this->assertNotNull($pending);
        $this->assertEquals(15000.00, (float) $pending->price);
        $this->assertSame('msg-early-price', $pending->message_id);
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
    public function it_updates_hidden_product_price(): void
    {
        // v13.2: HiddenScope не должен прятать товар от ERP-обработчика.
        $product = Product::factory()->create([
            'external_id' => 'hidden-price-uuid',
            'base_price' => 100.00,
            'hidden' => true,
        ]);

        $handler = new HandlePriceUpdated;
        $handler->handle([
            'event' => 'price.updated',
            'product_uuid' => 'hidden-price-uuid',
            'price' => 999.00,
        ]);

        $product = Product::withoutGlobalScopes()->find($product->id);
        $this->assertEquals(999.00, (float) $product->base_price);
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
