<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Полки наличия в сортировках витрины.
 *
 * Жалоба, из которой выросла задача: каталог открывался «Новинками», и наверху
 * стояли предзаказные позиции с нулевой ценой — по дате создания они и правда
 * самые свежие. Теперь обе витринные сортировки сначала раскладывают выдачу
 * по полкам наличия (по живым остаткам региона), и только внутри полки
 * работают балл или дата.
 */
class CatalogSortShelvesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $primary;

    private Warehouse $preorder;

    protected function setUp(): void
    {
        parent::setUp();

        $region = Region::factory()->create();
        $this->primary = Warehouse::factory()->create();
        $this->preorder = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            [
                'region_id' => $region->id,
                'warehouse_id' => $this->primary->id,
                'type' => 'primary',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'region_id' => $region->id,
                'warehouse_id' => $this->preorder->id,
                'type' => 'preorder',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_default_sort_puts_in_stock_above_preorder_and_orders_by_score(): void
    {
        // Порядок создания обратный ожидаемому: вторичная сортировка — id desc,
        // и без полок с баллом выдача пришла бы ровно наоборот.
        $preorderTop = $this->product(score: 900, preorder: 5);
        $inStockLow = $this->product(score: 10, stock: 5);
        $inStockTop = $this->product(score: 800, stock: 5);

        $ids = $this->catalogIds('default');

        $this->assertSame([$inStockTop->id, $inStockLow->id, $preorderTop->id], $ids);
    }

    public function test_newest_sort_puts_new_in_stock_first_then_rest_of_stock_by_date(): void
    {
        $preorderNewest = $this->product(preorder: 5, isNew: true, createdAt: now());
        $inStockOld = $this->product(stock: 5, createdAt: now()->subDays(10));
        $inStockFresh = $this->product(stock: 5, createdAt: now()->subDay());
        // Новинка в наличии самая старая из всех — и всё равно первая.
        $inStockNew = $this->product(stock: 5, isNew: true, createdAt: now()->subDays(30));

        $ids = $this->catalogIds('newest');

        $this->assertSame(
            [$inStockNew->id, $inStockFresh->id, $inStockOld->id, $preorderNewest->id],
            $ids,
        );
    }

    public function test_default_sort_is_used_when_sort_is_not_passed(): void
    {
        $low = $this->product(score: 10, stock: 5);
        $top = $this->product(score: 900, stock: 5);

        $response = $this->getJson('/api/catalog/products')->assertOk();

        $this->assertSame(
            [$top->id, $low->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    /**
     * @return array<int, int>
     */
    private function catalogIds(string $sort): array
    {
        $response = $this->getJson('/api/catalog/products?sort='.$sort)->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }

    private function product(
        float $score = 0,
        int $stock = 0,
        int $preorder = 0,
        bool $isNew = false,
        ?\Illuminate\Support\Carbon $createdAt = null,
    ): Product {
        $product = Product::factory()->create([
            'hidden' => false,
            'base_price' => 100,
            'is_new' => $isNew,
            'created_at' => $createdAt ?? now(),
        ]);

        // Балл вне $fillable: его пишет только команда пересчёта, запросом.
        DB::table('products')->where('id', $product->id)->update(['sort_score' => $score]);

        foreach ([[$this->primary->id, $stock], [$this->preorder->id, $preorder]] as [$warehouseId, $quantity]) {
            if ($quantity > 0) {
                DB::table('product_warehouse')->insert([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                ]);
            }
        }

        return $product;
    }
}
