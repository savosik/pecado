<?php

namespace Tests\Unit\Services\Pricing;

use App\Contracts\Currency\CurrencyConversionServiceInterface;
use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pricing\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PriceServiceBatchMapTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): PriceService
    {
        return new PriceService(
            Mockery::mock(CurrencyConversionServiceInterface::class),
            Mockery::mock(UserCurrencyResolverInterface::class),
        );
    }

    public function test_returns_empty_array_for_empty_collection(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-batch-empty']);

        $this->assertSame([], $this->makeService()->getPriceMapForProducts(collect(), $user));
    }

    public function test_returns_base_price_for_guest(): void
    {
        $products = collect([
            Product::create(['name' => 'p1', 'base_price' => 100.00, 'external_id' => 'pb-guest-1']),
            Product::create(['name' => 'p2', 'base_price' => 250.00, 'external_id' => 'pb-guest-2']),
        ]);

        $map = $this->makeService()->getPriceMapForProducts($products, null);

        $this->assertCount(2, $map);
        $this->assertSame(100.00, $map[$products[0]->id]->getDisplayPrice());
        $this->assertFalse($map[$products[0]->id]->hasDiscount);
        $this->assertSame(250.00, $map[$products[1]->id]->getDisplayPrice());
    }

    public function test_returns_base_price_when_user_has_no_erp_id(): void
    {
        $user = User::factory()->create(['erp_id' => null]);
        $products = collect([
            Product::create(['name' => 'p1', 'base_price' => 100.00, 'external_id' => 'pb-noerp-1']),
        ]);

        $map = $this->makeService()->getPriceMapForProducts($products, $user);

        $this->assertCount(1, $map);
        $this->assertFalse($map[$products[0]->id]->hasDiscount);
    }

    public function test_applies_individual_prices_from_batch_query(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-batch-001']);
        $warehouse = Warehouse::factory()->create();

        $p1 = Product::create(['name' => 'p1', 'base_price' => 100.00, 'external_id' => 'pb-001']);
        $p2 = Product::create(['name' => 'p2', 'base_price' => 200.00, 'external_id' => 'pb-002']);
        $p3 = Product::create(['name' => 'p3', 'base_price' => 300.00, 'external_id' => 'pb-003']);

        IndividualPrice::create([
            'partner_id' => $user->id,
            'product_id' => $p1->id,
            'warehouse_id' => $warehouse->id,
            'price' => 70.00,
        ]);
        IndividualPrice::create([
            'partner_id' => $user->id,
            'product_id' => $p3->id,
            'warehouse_id' => $warehouse->id,
            'price' => 240.00,
        ]);

        $map = $this->makeService()->getPriceMapForProducts(collect([$p1, $p2, $p3]), $user);

        $this->assertCount(3, $map);
        $this->assertSame(70.00, $map[$p1->id]->getDisplayPrice());
        $this->assertTrue($map[$p1->id]->hasDiscount);
        $this->assertSame(200.00, $map[$p2->id]->getDisplayPrice());
        $this->assertFalse($map[$p2->id]->hasDiscount);
        $this->assertSame(240.00, $map[$p3->id]->getDisplayPrice());
        $this->assertTrue($map[$p3->id]->hasDiscount);
    }

    public function test_uses_single_query_to_prices_db_regardless_of_collection_size(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-batch-q1']);
        $warehouse = Warehouse::factory()->create();

        $products = collect();
        for ($i = 0; $i < 25; $i++) {
            $product = Product::create([
                'name' => "p{$i}",
                'base_price' => 100.00,
                'external_id' => "pb-q1-{$i}",
            ]);
            $products->push($product);

            if ($i % 3 === 0) {
                IndividualPrice::create([
                    'partner_id' => $user->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'price' => 50.00,
                ]);
            }
        }

        // Connection::listen() регистрирует колбэк на общий диспетчер событий,
        // поэтому без фильтра сюда попадали бы запросы всех соединений
        // (например, резолв региона в основной БД) — считаем только prices.
        $queryCount = 0;
        DB::connection('prices')->listen(function ($query) use (&$queryCount) {
            if ($query->connectionName === 'prices') {
                $queryCount++;
            }
        });

        $this->makeService()->getPriceMapForProducts($products, $user);

        $this->assertSame(1, $queryCount, 'Должен быть ровно один SELECT в prices DB на коллекцию любого размера');
    }
}
