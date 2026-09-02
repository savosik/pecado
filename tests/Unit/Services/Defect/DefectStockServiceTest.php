<?php

namespace Tests\Unit\Services\Defect;

use App\Enums\DefectClosedReason;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use App\Services\Defect\DefectStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefectStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DefectStockService
    {
        return new DefectStockService;
    }

    /**
     * Создаёт заказ уценки на партию — именно так возникает резерв.
     */
    private function orderFor(ProductDefect $defect, int $quantity, OrderType $type = OrderType::DEFECT): Order
    {
        $order = Order::factory()->create(['type' => $type]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
            'defect_description' => $defect->defect_description,
            'name' => 'Позиция уценки',
            'price' => 100,
            'base_price' => 100,
            'discount_percent' => 0,
            'final_price' => 100,
            'quantity' => $quantity,
            'subtotal' => 100 * $quantity,
        ]);

        return $order;
    }

    public function test_available_equals_quantity_without_orders(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 3]);

        $this->assertSame(3, $this->service()->available($defect));
        $this->assertSame(0, $this->service()->reserved($defect));
    }

    public function test_defect_order_reserves_quantity(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 5]);
        $this->orderFor($defect, 2);

        $this->assertSame(2, $this->service()->reserved($defect));
        $this->assertSame(3, $this->service()->available($defect));
    }

    public function test_deleting_order_releases_reservation(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 2]);
        $order = $this->orderFor($defect, 2);

        $this->assertSame(0, $this->service()->available($defect));

        $order->delete();

        $this->assertSame(2, $this->service()->available($defect), 'Удаление заказа должно снимать резерв');
    }

    public function test_non_defect_order_does_not_reserve(): void
    {
        // Страховка от случайной ссылки на партию из обычного заказа.
        $defect = ProductDefect::factory()->create(['quantity' => 4]);
        $this->orderFor($defect, 3, OrderType::ORDER);

        $this->assertSame(4, $this->service()->available($defect));
    }

    public function test_available_never_goes_negative(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 1]);
        $this->orderFor($defect, 5);

        $this->assertSame(0, $this->service()->available($defect));
    }

    public function test_available_map_is_batched_and_correct(): void
    {
        $a = ProductDefect::factory()->create(['quantity' => 5]);
        $b = ProductDefect::factory()->create(['quantity' => 2]);
        $c = ProductDefect::factory()->create(['quantity' => 7]);

        $this->orderFor($a, 1);
        $this->orderFor($b, 2);

        $map = $this->service()->availableMap(collect([$a, $b, $c]));

        $this->assertSame([$a->id => 4, $b->id => 0, $c->id => 7], $map);
    }

    public function test_available_map_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], $this->service()->availableMap(collect()));
    }

    public function test_sellable_scope_requires_price_and_publication(): void
    {
        $product = Product::factory()->create();

        $draft = ProductDefect::factory()->for($product)->create();
        $pricedOnly = ProductDefect::factory()->for($product)->priced(500)->create();
        $sellable = ProductDefect::factory()->for($product)->sellable(300)->create();
        $closed = ProductDefect::factory()->for($product)->sellable(200)->closed()->create();

        $ids = ProductDefect::query()->sellable()->pluck('id')->all();

        $this->assertSame([$sellable->id], $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($pricedOnly->id, $ids);
        $this->assertNotContains($closed->id, $ids);
    }

    public function test_has_sellable_defects_map_ignores_fully_reserved_defects(): void
    {
        $withStock = Product::factory()->create();
        $reserved = Product::factory()->create();
        $without = Product::factory()->create();

        ProductDefect::factory()->for($withStock)->sellable(100)->create(['quantity' => 2]);

        $taken = ProductDefect::factory()->for($reserved)->sellable(100)->create(['quantity' => 1]);
        $this->orderFor($taken, 1);

        $map = $this->service()->hasSellableDefectsMap([$withStock->id, $reserved->id, $without->id]);

        $this->assertTrue($map[$withStock->id]);
        $this->assertFalse($map[$reserved->id], 'Полностью зарезервированная партия не считается доступной');
        $this->assertFalse($map[$without->id]);
    }

    public function test_min_sellable_price_map_returns_min_price_of_available_defects(): void
    {
        $product = Product::factory()->create();
        $reservedProduct = Product::factory()->create();
        $without = Product::factory()->create();

        // Две доступные партии — в карту попадает минимальная цена.
        ProductDefect::factory()->for($product)->sellable(500)->create(['quantity' => 2]);
        ProductDefect::factory()->for($product)->sellable(300)->create(['quantity' => 1]);

        // Дешёвая, но полностью выбранная партия минимум не занижает.
        $taken = ProductDefect::factory()->for($product)->sellable(100)->create(['quantity' => 1]);
        $this->orderFor($taken, 1);

        // Товар с единственной полностью зарезервированной партией в карту не попадает.
        $fullyTaken = ProductDefect::factory()->for($reservedProduct)->sellable(50)->create(['quantity' => 1]);
        $this->orderFor($fullyTaken, 1);

        $map = $this->service()->minSellablePriceMap([$product->id, $reservedProduct->id, $without->id]);

        $this->assertSame([$product->id => 300.0], $map);
        $this->assertArrayNotHasKey($reservedProduct->id, $map);
        $this->assertArrayNotHasKey($without->id, $map);
    }

    public function test_min_sellable_price_map_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], $this->service()->minSellablePriceMap([]));
    }

    public function test_sellable_for_product_exposes_available_quantity(): void
    {
        $product = Product::factory()->create();

        $defect = ProductDefect::factory()->for($product)->sellable(150)->create(['quantity' => 4]);
        $this->orderFor($defect, 1);

        // Полностью выбранная партия в выдачу попасть не должна.
        $soldOut = ProductDefect::factory()->for($product)->sellable(50)->create(['quantity' => 1]);
        $this->orderFor($soldOut, 1);

        $result = $this->service()->sellableForProduct($product);

        $this->assertCount(1, $result);
        $this->assertSame($defect->id, $result->first()->id);
        $this->assertSame(3, $result->first()->available_quantity);
    }

    public function test_closed_defect_is_not_sellable(): void
    {
        $defect = ProductDefect::factory()->sellable(100)->create();

        $defect->close(DefectClosedReason::WRITTEN_OFF);

        $this->assertTrue($defect->fresh()->isClosed());
        $this->assertSame(DefectClosedReason::WRITTEN_OFF, $defect->fresh()->closed_reason);
        $this->assertSame(0, ProductDefect::query()->sellable()->count());
    }

    public function test_defect_without_price_cannot_be_published(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->assertFalse($defect->canBePublished());

        $priced = ProductDefect::factory()->priced(100)->create();
        $this->assertTrue($priced->canBePublished());
    }

    public function test_uuid_is_generated_on_create(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->assertNotEmpty($defect->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $defect->uuid
        );
    }

    public function test_product_relation_exposes_defects(): void
    {
        $product = Product::factory()->create();
        ProductDefect::factory()->for($product)->count(2)->create();

        $this->assertCount(2, $product->fresh()->defects);
    }

    public function test_user_can_be_defect_creator_and_pricer(): void
    {
        $storekeeper = User::factory()->create();
        $buyer = User::factory()->create();

        $defect = ProductDefect::factory()->create([
            'created_by' => $storekeeper->id,
            'priced_by' => $buyer->id,
        ]);

        $this->assertTrue($defect->creator->is($storekeeper));
        $this->assertTrue($defect->pricer->is($buyer));
    }
}
