<?php

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\ProductStockBuffer;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Поиск товаров в кабинетных корзинах считал остатки собственной SUM-копией
 * мимо StockService и потому не вычитал страховой буфер (buf-04): клиент
 * сегмента `stock_buffer_enabled` видел здесь больше, чем в каталоге и карточке.
 * Теперь остатки берутся из общей точки агрегации.
 */
class CabinetCartStockBufferTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::factory()->create();
        $primary = Warehouse::factory()->create();
        $preorder = Warehouse::factory()->create();

        DB::table('region_warehouse')->insert([
            ['region_id' => $this->region->id, 'warehouse_id' => $primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $this->region->id, 'warehouse_id' => $preorder->id, 'type' => 'preorder', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->product = Product::factory()->create(['name' => 'Буферный тестовый товар']);
        $this->product->warehouses()->attach($primary->id, ['quantity' => 5]);
        $this->product->warehouses()->attach($preorder->id, ['quantity' => 4]);

        ProductStockBuffer::create(['product_id' => $this->product->id, 'buffer_qty' => 2]);
    }

    private function client(bool $flagged): User
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);

        if ($flagged) {
            $user->forceFill(['stock_buffer_enabled' => true])->save();
        }

        return $user;
    }

    /**
     * @return array{stock_available: int, stock_preorder: int}
     */
    private function searchStock(User $user): array
    {
        $response = $this->actingAs($user)
            ->getJson(route('cabinet.carts.search-products', ['query' => 'Буферный']));

        $response->assertOk();

        $row = collect($response->json())->firstWhere('id', $this->product->id);
        $this->assertNotNull($row, 'Товар должен находиться поиском по названию');

        return [
            'stock_available' => (int) $row['stock_available'],
            'stock_preorder' => (int) $row['stock_preorder'],
        ];
    }

    #[Test]
    public function клиент_сегмента_видит_остаток_за_вычетом_буфера(): void
    {
        config(['stock_buffer.enabled' => true]);

        $this->assertSame(
            ['stock_available' => 3, 'stock_preorder' => 4],
            $this->searchStock($this->client(true)),
            'available = max(0, 5 − 2), preorder не занижается',
        );
    }

    #[Test]
    public function клиент_без_галочки_видит_полный_остаток(): void
    {
        config(['stock_buffer.enabled' => true]);

        $this->assertSame(
            ['stock_available' => 5, 'stock_preorder' => 4],
            $this->searchStock($this->client(false)),
        );
    }

    #[Test]
    public function при_выключенном_флаге_буфер_не_применяется(): void
    {
        config(['stock_buffer.enabled' => false]);

        $this->assertSame(
            ['stock_available' => 5, 'stock_preorder' => 4],
            $this->searchStock($this->client(true)),
        );
    }

    #[Test]
    public function корзины_и_карточка_показывают_один_и_тот_же_остаток(): void
    {
        config(['stock_buffer.enabled' => true]);
        $user = $this->client(true);

        $service = app(\App\Contracts\Stock\StockServiceInterface::class);

        $this->assertSame(
            $service->getStock($this->product, $user)['available'],
            $this->searchStock($user)['stock_available'],
        );
    }
}
