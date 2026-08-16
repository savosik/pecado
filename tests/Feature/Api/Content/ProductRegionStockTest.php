<?php

namespace Tests\Feature\Api\Content;

use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Подмена региона через ?region_id в Content API (DoD buf-03).
 *
 * ИИ-агент контент-менеджера смотрит остатки чужого региона: параметр мутирует
 * region_id пользователя в памяти запроса, и весь SQL остатков (StockService)
 * обязан считать по складам подменённого региона.
 */
class ProductRegionStockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Product $product;

    private Region $homeRegion;

    private Region $otherRegion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->homeRegion = Region::factory()->create(['name' => 'Домашний']);
        $this->otherRegion = Region::factory()->create(['name' => 'Чужой']);

        $homeWarehouse = Warehouse::factory()->create();
        $otherWarehouse = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            [
                'region_id' => $this->homeRegion->id,
                'warehouse_id' => $homeWarehouse->id,
                'type' => 'primary',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'region_id' => $this->otherRegion->id,
                'warehouse_id' => $otherWarehouse->id,
                'type' => 'primary',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->product = Product::factory()->create();
        $this->product->warehouses()->attach($homeWarehouse->id, ['quantity' => 11]);
        $this->product->warehouses()->attach($otherWarehouse->id, ['quantity' => 4]);

        $this->user = User::factory()->create(['region_id' => $this->homeRegion->id]);
        $this->token = $this->user->createToken('test', ['*'])->plainTextToken;
    }

    private function indexJson(array $query = []): array
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson('/api/content/products?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_index_uses_own_region_by_default(): void
    {
        $row = collect($this->indexJson())->firstWhere('id', $this->product->id);

        $this->assertSame(11, $row['stock_quantity']);
    }

    public function test_region_id_substitutes_stock_region(): void
    {
        $row = collect($this->indexJson(['region_id' => $this->otherRegion->id]))
            ->firstWhere('id', $this->product->id);

        $this->assertSame(4, $row['stock_quantity'], 'Остатки должны считаться по складам подменённого региона');
    }
}
