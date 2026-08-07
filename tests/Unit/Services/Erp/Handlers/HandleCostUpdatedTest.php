<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Product;
use App\Services\Erp\Handlers\HandleCostUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleCostUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_product_cost_price(): void
    {
        $product = Product::factory()->create([
            'external_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $handler = new HandleCostUpdated;
        $handler->handle([
            'event' => 'cost.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'cost' => 8450.00,
        ]);

        $product->refresh();

        $this->assertEquals(8450.00, (float) $product->cost_price);
        $this->assertNotNull($product->cost_price_updated_at);
    }

    #[Test]
    public function it_accepts_explicit_rub_currency(): void
    {
        $product = Product::factory()->create(['external_id' => 'rub-cost-uuid']);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'rub-cost-uuid',
            'cost' => 1200.00,
            'currency_code' => 'RUB',
        ]);

        $product->refresh();

        $this->assertEquals(1200.00, (float) $product->cost_price);
    }

    #[Test]
    public function it_rejects_foreign_currency(): void
    {
        // Пересчёт по курсу сознательно не делается: себестоимость поехала бы
        // задним числом вместе с курсом.
        $product = Product::factory()->create(['external_id' => 'usd-cost-uuid']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'себестоимость не в рублях'));

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'usd-cost-uuid',
            'cost' => 100.00,
            'currency_code' => 'USD',
        ]);

        $product->refresh();

        $this->assertNull($product->cost_price);
    }

    #[Test]
    public function it_ignores_unknown_product_without_error(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'товар не найден по UUID'));

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'nonexistent-uuid-1234',
            'cost' => 8450.00,
        ]);
    }

    #[Test]
    public function it_does_nothing_when_product_uuid_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'отсутствует product_uuid или cost'));

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'cost' => 8450.00,
        ]);
    }

    #[Test]
    public function it_does_nothing_when_cost_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'отсутствует product_uuid или cost'));

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);
    }

    #[Test]
    public function it_updates_cost_to_zero(): void
    {
        $product = Product::factory()->create(['external_id' => 'zero-cost-uuid']);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'zero-cost-uuid',
            'cost' => 0,
        ]);

        $product->refresh();

        $this->assertEquals(0, (float) $product->cost_price);
    }

    #[Test]
    public function it_updates_hidden_product_cost(): void
    {
        // HiddenScope не должен прятать товар от ERP-обработчика: себестоимость
        // нужна и по снятым с публикации товарам — из них состоят прошлые отгрузки.
        $product = Product::factory()->create([
            'external_id' => 'hidden-cost-uuid',
            'hidden' => true,
        ]);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'hidden-cost-uuid',
            'cost' => 999.00,
        ]);

        $product = Product::withoutGlobalScopes()->find($product->id);

        $this->assertEquals(999.00, (float) $product->cost_price);
    }

    #[Test]
    public function it_overwrites_existing_cost(): void
    {
        $product = Product::factory()->create(['external_id' => 'overwrite-cost-uuid']);

        $handler = new HandleCostUpdated;

        $handler->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'overwrite-cost-uuid',
            'cost' => 20000.00,
        ]);

        $product->refresh();
        $this->assertEquals(20000.00, (float) $product->cost_price);

        $handler->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'overwrite-cost-uuid',
            'cost' => 7500.50,
        ]);

        $product->refresh();
        $this->assertEquals(7500.50, (float) $product->cost_price);
    }

    #[Test]
    public function it_does_not_touch_base_price(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'base-price-untouched-uuid',
            'base_price' => 12500.50,
        ]);

        (new HandleCostUpdated)->handle([
            'event' => 'cost.updated',
            'product_uuid' => 'base-price-untouched-uuid',
            'cost' => 8450.00,
        ]);

        $product->refresh();

        $this->assertEquals(12500.50, (float) $product->base_price);
    }
}
