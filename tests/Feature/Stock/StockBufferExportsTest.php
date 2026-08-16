<?php

namespace Tests\Feature\Stock;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\ProductStockBuffer;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\ClientLifecycleService;
use App\Services\ProductExportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Буфер в выгрузках, client-api и условная инвалидация кешей (карточка buf-05).
 *
 * Выгрузка — главная поверхность дропшиппера: занижение обязано совпадать
 * с сайтом до штуки, а кеши — протухать строго адресно.
 */
class StockBufferExportsTest extends TestCase
{
    use RefreshDatabase;

    private Region $region;

    private Warehouse $primary;

    private Warehouse $secondPrimary;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::factory()->create();
        $this->primary = Warehouse::factory()->create(['name' => 'Основной']);
        $this->secondPrimary = Warehouse::factory()->create(['name' => 'Резервный']);

        DB::table('region_warehouse')->insert([
            ['region_id' => $this->region->id, 'warehouse_id' => $this->primary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
            ['region_id' => $this->region->id, 'warehouse_id' => $this->secondPrimary->id, 'type' => 'primary', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->product = Product::factory()->create();
        DB::table('product_warehouse')->insert([
            ['product_id' => $this->product->id, 'warehouse_id' => $this->primary->id, 'quantity' => 1],
            ['product_id' => $this->product->id, 'warehouse_id' => $this->secondPrimary->id, 'quantity' => 4],
        ]);

        ProductStockBuffer::create(['product_id' => $this->product->id, 'buffer_qty' => 2]);
        config(['stock_buffer.enabled' => true]);
    }

    private function client(bool $flagged): User
    {
        $user = User::factory()->create(['region_id' => $this->region->id]);

        if ($flagged) {
            $user->forceFill(['stock_buffer_enabled' => true])->save();
        }

        return $user;
    }

    private function exportRow(array $fields, ?User $client): array
    {
        $export = (new ProductExport)->forceFill([
            'client_user_id' => $client?->id,
            'fields' => $fields,
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => $this->product->id]],
        ]);

        return app(ProductExportService::class)->fetchData($export, 1)->first();
    }

    public function test_warehouse_columns_agree_with_user_stock_for_flagged_client(): void
    {
        $row = $this->exportRow([
            ['key' => 'user_stock_available'],
            ['key' => 'total_stock'],
            ['key' => "warehouse.{$this->primary->id}.quantity"],
            ['key' => "warehouse.{$this->secondPrimary->id}.quantity"],
        ], $this->client(true));

        // Остаток 5, буфер 2: первый склад с остатком «съедается» первым (1 → 0),
        // остаток буфера — со второго (4 → 3). Сумма по складам = user_stock.
        $this->assertSame(3, $row['user_stock_available']);
        $this->assertSame(3, $row['total_stock']);
        $this->assertSame(0, $row["warehouse.{$this->primary->id}.quantity"]);
        $this->assertSame(3, $row["warehouse.{$this->secondPrimary->id}.quantity"]);
    }

    public function test_unflagged_client_and_clientless_export_see_full_stock(): void
    {
        $fields = [
            ['key' => 'user_stock_available'],
            ['key' => 'total_stock'],
        ];

        $unflagged = $this->exportRow($fields, $this->client(false));
        $this->assertSame(5, $unflagged['user_stock_available']);
        $this->assertSame(5, $unflagged['total_stock']);

        // Выгрузка без клиента — внутренний инструмент, буфер не применяется.
        $clientless = $this->exportRow([['key' => 'total_stock']], null);
        $this->assertSame(5, $clientless['total_stock']);
    }

    public function test_negative_modifier_does_not_push_stock_below_zero(): void
    {
        $row = $this->exportRow([
            ['key' => 'total_stock', 'modifiers' => ['add' => -10]],
        ], $this->client(false));

        $this->assertSame(0, $row['total_stock'], 'Ручное add: -10 не должно уводить остаток в минус');
    }

    public function test_client_api_stocks_are_buffered_for_flagged_client(): void
    {
        $client = $this->client(true);
        $token = \App\Models\ApiToken::create([
            'user_id' => $client->id,
            'name' => 'test',
            'token' => str_repeat('a', 48),
            'is_active' => true,
        ]);

        $row = collect($this->getJson("/api/client-api/{$token->token}/stocks")
            ->assertOk()
            ->json('data'))->firstWhere('sku', $this->product->sku);

        $this->assertSame(3, $row['available'], 'client-api обязан совпадать с сайтом до штуки');
    }

    public function test_recompute_with_changes_resets_only_segment_export_caches(): void
    {
        $flagged = $this->client(true);
        $unflagged = $this->client(false);

        $flaggedExport = $this->makeCachedExport($flagged);
        $unflaggedExport = $this->makeCachedExport($unflagged);

        // Дифф непустой: сигналов у товара нет, расчётный буфер обнулится.
        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertNull($flaggedExport->refresh()->cached_at, 'Кеш клиента сегмента сброшен');
        $this->assertNotNull($unflaggedExport->refresh()->cached_at, 'Клиент без галочки не задет');
    }

    public function test_empty_recompute_touches_no_caches(): void
    {
        // Буфер уже соответствует сигналам? Нет: удаляем запись, сигналов нет —
        // пересчёт ничего не меняет.
        ProductStockBuffer::query()->delete();

        $flaggedExport = $this->makeCachedExport($this->client(true));

        $this->artisan('stock:buffers:recompute')->assertSuccessful();

        $this->assertNotNull($flaggedExport->refresh()->cached_at, 'Пустой пересчёт не трогает ни один кеш');
    }

    public function test_toggling_flag_resets_only_this_clients_exports(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');

        $client = $this->client(false);
        $other = $this->client(true);

        $clientExport = $this->makeCachedExport($client);
        $otherExport = $this->makeCachedExport($other);

        app(ClientLifecycleService::class)->changeStockBuffer($client, true, $manager);

        $this->assertNull($clientExport->refresh()->cached_at, 'Кеш переключённого клиента сброшен');
        $this->assertNotNull($otherExport->refresh()->cached_at, 'Чужие кеши не тронуты');
    }

    private function makeCachedExport(User $client): ProductExport
    {
        return ProductExport::create([
            'user_id' => $client->id,
            'client_user_id' => $client->id,
            'name' => 'test-'.$client->id,
            'format' => 'json',
            'preset' => 'test',
            'filters' => [],
            'fields' => [],
            'is_active' => true,
            'cached_at' => now(),
        ]);
    }
}
