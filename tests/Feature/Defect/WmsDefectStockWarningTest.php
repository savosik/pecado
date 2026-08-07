<?php

namespace Tests\Feature\Defect;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Предупреждение кладовщику: товара нет на остатках склада некондиции.
 *
 * Остатки приходят из 1С в pivot product_warehouse. Партию заводить не
 * запрещаем — только предупреждаем: остатки могут прийти позже приёмки.
 */
class WmsDefectStockWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function storekeeper(): User
    {
        $user = User::factory()->create();
        $user->assignRole('storekeeper');

        return $user;
    }

    private function defectWarehouse(): Warehouse
    {
        return Warehouse::factory()->defect()->create(['name' => 'Некондиция']);
    }

    private function setStock(Product $product, Warehouse $warehouse, int $quantity): void
    {
        $product->warehouses()->syncWithoutDetaching([
            $warehouse->id => ['quantity' => $quantity],
        ]);
    }

    #[Test]
    public function product_search_returns_defect_warehouse_stock(): void
    {
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create(['name' => 'Тестовый вибратор']);
        $this->setStock($product, $warehouse, 4);

        $response = $this->actingAs($this->storekeeper())
            ->getJson('/wms/defects/search-products?query=Тестовый')
            ->assertOk();

        $row = collect($response->json())->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertSame(4, $row['defect_stock'][(string) $warehouse->id]);
    }

    #[Test]
    public function barcode_resolve_returns_defect_warehouse_stock(): void
    {
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create(['barcode' => '4600000000017']);

        $this->actingAs($this->storekeeper())
            ->getJson('/wms/defects/resolve-barcode?barcode=4600000000017')
            ->assertOk()
            ->assertJsonPath('found', true)
            // Остатка нет вовсе — карта пустая, фронт трактует это как ноль.
            ->assertJsonPath('product.defect_stock', []);

        $this->setStock($product, $warehouse, 2);

        $this->actingAs($this->storekeeper())
            ->getJson('/wms/defects/resolve-barcode?barcode=4600000000017')
            ->assertOk()
            ->assertJsonPath("product.defect_stock.{$warehouse->id}", 2);
    }

    #[Test]
    public function storing_batch_without_stock_flashes_warning(): void
    {
        Storage::fake('public');
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create();

        $this->actingAs($this->storekeeper())
            ->post('/wms/defects', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Порвана упаковка',
                'quantity' => 2,
                'photos' => [UploadedFile::fake()->image('defect.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');
    }

    #[Test]
    public function storing_batch_over_stock_flashes_warning(): void
    {
        Storage::fake('public');
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create();
        $this->setStock($product, $warehouse, 1);

        $this->actingAs($this->storekeeper())
            ->post('/wms/defects', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Порвана упаковка',
                'quantity' => 3,
                'photos' => [UploadedFile::fake()->image('defect.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');
    }

    #[Test]
    public function storing_batch_over_undistributed_remainder_flashes_warning(): void
    {
        // Остатка формально хватает, но он уже расписан другими партиями —
        // иначе один и тот же брак уехал бы на витрину дважды.
        Storage::fake('public');
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create();
        $this->setStock($product, $warehouse, 5);

        \App\Models\ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 4,
        ]);

        $this->actingAs($this->storekeeper())
            ->post('/wms/defects', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Порвана упаковка',
                'quantity' => 2,
                'photos' => [UploadedFile::fake()->image('defect.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning', fn (?string $warning) => $warning !== null
                && str_contains($warning, 'уже разобрано другими партиями'));
    }

    #[Test]
    public function storing_batch_within_stock_has_no_warning(): void
    {
        Storage::fake('public');
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create();
        $this->setStock($product, $warehouse, 5);

        $this->actingAs($this->storekeeper())
            ->post('/wms/defects', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Порвана упаковка',
                'quantity' => 3,
                'photos' => [UploadedFile::fake()->image('defect.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionMissing('warning');
    }

    #[Test]
    public function quick_store_returns_warning_when_stock_is_missing(): void
    {
        Storage::fake('public');
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create();

        $this->actingAs($this->storekeeper())
            ->post('/wms/defects/quick', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Помята коробка',
                'quantity' => 1,
                'photos' => [UploadedFile::fake()->image('defect.jpg')],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('warning', fn ($warning) => is_string($warning) && $warning !== '');
    }

    #[Test]
    public function quick_store_has_no_warning_when_stock_is_enough(): void
    {
        Storage::fake('public');
        $warehouse = $this->defectWarehouse();
        $product = Product::factory()->create();
        $this->setStock($product, $warehouse, 3);

        $this->actingAs($this->storekeeper())
            ->post('/wms/defects/quick', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Помята коробка',
                'quantity' => 1,
                'photos' => [UploadedFile::fake()->image('defect.jpg')],
            ])
            ->assertCreated()
            ->assertJsonPath('warning', null);
    }
}
