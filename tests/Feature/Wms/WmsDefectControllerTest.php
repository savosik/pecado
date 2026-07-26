<?php

namespace Tests\Feature\Wms;

use App\Enums\DefectClosedReason;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsDefectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    private function storekeeper(): User
    {
        $user = User::factory()->create();
        $user->assignRole('storekeeper');

        return $user;
    }

    private function defectWarehouse(): Warehouse
    {
        return Warehouse::factory()->defect()->create();
    }

    /** Резерв возникает только через заказ уценки. */
    private function reserve(ProductDefect $defect, int $quantity): Order
    {
        $order = Order::factory()->create(['type' => OrderType::DEFECT]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
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

    // ────────────────────────────────────────────
    // Доступ
    // ────────────────────────────────────────────

    #[Test]
    public function guest_cannot_open_defects(): void
    {
        $this->get('/wms/defects')->assertRedirect('/login');
    }

    #[Test]
    public function client_without_wms_permissions_cannot_open_defects(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/wms/defects')
            ->assertRedirect('/');
    }

    #[Test]
    public function storekeeper_can_open_defects_list(): void
    {
        $this->actingAs($this->storekeeper())
            ->get('/wms/defects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Wms/Pages/Defects/Index'));
    }

    #[Test]
    public function storekeeper_sees_defect_codes_legend(): void
    {
        // Уникальные имена: базовый справочник дефектов уже засеян.
        $active = \App\Models\DefectType::create(['name' => 'Зедефект-Альфа', 'is_active' => true, 'sort_order' => 100]);
        \App\Models\DefectType::create(['name' => 'Зедефект-Бета', 'is_active' => false, 'sort_order' => 101]);
        $total = \App\Models\DefectType::count();

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects/codes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Defects/Codes')
                ->has('codes', $total)
                // Отсортировано по коду (id) — мои новые записи последние.
                ->where('codes.'.($total - 2).'.id', $active->id)
                ->where('codes.'.($total - 2).'.name', 'Зедефект-Альфа')
                ->where('codes.'.($total - 2).'.is_active', true)
                // Неактивные типы тоже в легенде.
                ->where('codes.'.($total - 1).'.is_active', false)
            );
    }

    #[Test]
    public function defect_codes_export_returns_xlsx(): void
    {
        \App\Models\DefectType::create(['name' => 'Зедефект-Экспорт', 'is_active' => true, 'sort_order' => 100]);

        $response = $this->actingAs($this->storekeeper())->get('/wms/defects/codes/export');

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function client_without_wms_permissions_cannot_open_defect_codes(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/wms/defects/codes')
            ->assertRedirect('/');
    }

    #[Test]
    public function defect_permissions_do_not_open_admin_panel(): void
    {
        // Префикс wms- обязан оставаться панельным, иначе кладовщик попадёт в /admin.
        $this->actingAs($this->storekeeper())
            ->get('/admin')
            ->assertRedirect('/');
    }

    // ────────────────────────────────────────────
    // Заведение партии
    // ────────────────────────────────────────────

    #[Test]
    public function storekeeper_creates_defect_with_photos(): void
    {
        $product = Product::factory()->create();
        $warehouse = $this->defectWarehouse();
        $storekeeper = $this->storekeeper();

        $this->actingAs($storekeeper)
            ->post('/wms/defects', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Порвана упаковка',
                'quantity' => 3,
                'photos' => [UploadedFile::fake()->image('defect.jpg')],
            ])
            ->assertRedirect();

        $defect = ProductDefect::first();

        $this->assertNotNull($defect);
        $this->assertSame($product->id, $defect->product_id);
        $this->assertSame(3, $defect->quantity);
        $this->assertSame($storekeeper->id, $defect->created_by);
        $this->assertCount(1, $defect->getMedia(ProductDefect::MEDIA_COLLECTION));
    }

    #[Test]
    public function new_defect_is_not_sellable_until_buyer_prices_it(): void
    {
        $product = Product::factory()->create();
        $warehouse = $this->defectWarehouse();

        $this->actingAs($this->storekeeper())->post('/wms/defects', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'defect_description' => 'Нет крышки батарейного отсека',
            'quantity' => 1,
        ]);

        $defect = ProductDefect::first();

        $this->assertNull($defect->price);
        $this->assertFalse($defect->is_published);
        $this->assertSame(0, ProductDefect::query()->sellable()->count());
    }

    #[Test]
    public function defect_cannot_be_created_on_regular_warehouse(): void
    {
        // Остатки обычных складов ведёт 1С — некондиция там всё бы рассинхронизировала.
        $regular = Warehouse::factory()->create(['is_defect' => false]);

        $this->actingAs($this->storekeeper())
            ->post('/wms/defects', [
                'product_id' => Product::factory()->create()->id,
                'warehouse_id' => $regular->id,
                'defect_description' => 'Помята коробка',
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('warehouse_id');

        $this->assertSame(0, ProductDefect::count());
    }

    #[Test]
    public function defect_requires_description_and_positive_quantity(): void
    {
        $this->actingAs($this->storekeeper())
            ->post('/wms/defects', [
                'product_id' => Product::factory()->create()->id,
                'warehouse_id' => $this->defectWarehouse()->id,
                'defect_description' => '',
                'quantity' => 0,
            ])
            ->assertSessionHasErrors(['defect_description', 'quantity']);
    }

    // ────────────────────────────────────────────
    // Правка партии
    // ────────────────────────────────────────────

    #[Test]
    public function storekeeper_updates_description_and_quantity(): void
    {
        $defect = ProductDefect::factory()->create(['quantity' => 2]);

        $this->actingAs($this->storekeeper())
            ->put("/wms/defects/{$defect->id}", [
                'defect_description' => 'Скол на корпусе',
                'quantity' => 5,
            ])
            ->assertRedirect();

        $defect->refresh();

        $this->assertSame('Скол на корпусе', $defect->defect_description);
        $this->assertSame(5, $defect->quantity);
    }

    #[Test]
    public function quantity_cannot_drop_below_reserved(): void
    {
        $defect = ProductDefect::factory()->sellable(500)->create(['quantity' => 4]);
        $this->reserve($defect, 3);

        $this->actingAs($this->storekeeper())
            ->put("/wms/defects/{$defect->id}", [
                'defect_description' => $defect->defect_description,
                'quantity' => 2,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(4, $defect->fresh()->quantity);
    }

    #[Test]
    public function storekeeper_can_remove_photo(): void
    {
        $defect = ProductDefect::factory()->create();
        $defect->addMedia(UploadedFile::fake()->image('old.jpg'))
            ->toMediaCollection(ProductDefect::MEDIA_COLLECTION);

        $mediaId = $defect->getFirstMedia(ProductDefect::MEDIA_COLLECTION)->id;

        $this->actingAs($this->storekeeper())
            ->put("/wms/defects/{$defect->id}", [
                'defect_description' => $defect->defect_description,
                'quantity' => $defect->quantity,
                'removed_media_ids' => [$mediaId],
            ])
            ->assertRedirect();

        $this->assertCount(0, $defect->fresh()->getMedia(ProductDefect::MEDIA_COLLECTION));
    }

    #[Test]
    public function storekeeper_cannot_remove_media_of_another_defect(): void
    {
        $target = ProductDefect::factory()->create();
        $other = ProductDefect::factory()->create();
        $other->addMedia(UploadedFile::fake()->image('other.jpg'))
            ->toMediaCollection(ProductDefect::MEDIA_COLLECTION);

        $foreignMediaId = $other->getFirstMedia(ProductDefect::MEDIA_COLLECTION)->id;

        $this->actingAs($this->storekeeper())
            ->put("/wms/defects/{$target->id}", [
                'defect_description' => $target->defect_description,
                'quantity' => $target->quantity,
                'removed_media_ids' => [$foreignMediaId],
            ])
            ->assertRedirect();

        $this->assertCount(1, $other->fresh()->getMedia(ProductDefect::MEDIA_COLLECTION));
    }

    // ────────────────────────────────────────────
    // Списание и удаление
    // ────────────────────────────────────────────

    #[Test]
    public function storekeeper_writes_off_defect(): void
    {
        $defect = ProductDefect::factory()->sellable(300)->create();

        $this->actingAs($this->storekeeper())
            ->post("/wms/defects/{$defect->id}/write-off")
            ->assertRedirect();

        $defect->refresh();

        $this->assertTrue($defect->isClosed());
        $this->assertSame(DefectClosedReason::WRITTEN_OFF, $defect->closed_reason);
        $this->assertSame(0, ProductDefect::query()->sellable()->count());
    }

    #[Test]
    public function reserved_defect_cannot_be_written_off(): void
    {
        $defect = ProductDefect::factory()->sellable(300)->create(['quantity' => 2]);
        $this->reserve($defect, 1);

        $this->actingAs($this->storekeeper())
            ->post("/wms/defects/{$defect->id}/write-off")
            ->assertSessionHas('error');

        $this->assertFalse($defect->fresh()->isClosed());
    }

    #[Test]
    public function reserved_defect_cannot_be_deleted(): void
    {
        $defect = ProductDefect::factory()->sellable(300)->create(['quantity' => 2]);
        $this->reserve($defect, 2);

        $this->actingAs($this->storekeeper())
            ->delete("/wms/defects/{$defect->id}")
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($defect);
    }

    #[Test]
    public function free_defect_can_be_deleted(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->actingAs($this->storekeeper())
            ->delete("/wms/defects/{$defect->id}")
            ->assertRedirect('/wms/defects');

        $this->assertSoftDeleted($defect);
    }

    #[Test]
    public function deleting_order_frees_defect_for_deletion(): void
    {
        $defect = ProductDefect::factory()->sellable(300)->create(['quantity' => 1]);
        $order = $this->reserve($defect, 1);

        $order->delete();

        $this->actingAs($this->storekeeper())
            ->delete("/wms/defects/{$defect->id}")
            ->assertRedirect('/wms/defects');

        $this->assertSoftDeleted($defect);
    }

    // ────────────────────────────────────────────
    // Поиск товара для формы
    // ────────────────────────────────────────────

    #[Test]
    public function product_search_finds_by_sku(): void
    {
        $product = Product::factory()->create(['name' => 'Вибратор Тест', 'sku' => 'SKU-9911']);
        Product::factory()->create(['name' => 'Другой товар', 'sku' => 'SKU-0002']);

        $response = $this->actingAs($this->storekeeper())
            ->getJson('/wms/defects/search-products?query=SKU-9911')
            ->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame($product->id, $response->json('0.id'));
    }

    #[Test]
    public function product_search_ignores_short_queries(): void
    {
        Product::factory()->create(['name' => 'Товар']);

        $this->actingAs($this->storekeeper())
            ->getJson('/wms/defects/search-products?query=a')
            ->assertOk()
            ->assertExactJson([]);
    }

    // ────────────────────────────────────────────
    // Выдача списка
    // ────────────────────────────────────────────

    #[Test]
    public function index_exposes_available_and_reserved_quantities(): void
    {
        $defect = ProductDefect::factory()->sellable(400)->create(['quantity' => 5]);
        $this->reserve($defect, 2);

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Defects/Index')
                ->where('defects.data.0.quantity', 5)
                ->where('defects.data.0.available_quantity', 3)
                ->where('defects.data.0.reserved_quantity', 2)
            );
    }

    #[Test]
    public function index_filters_unpriced_defects(): void
    {
        ProductDefect::factory()->create(['defect_description' => 'Без цены']);
        ProductDefect::factory()->sellable(100)->create(['defect_description' => 'С ценой']);

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects?filter=unpriced')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('defects.data', 1)
                ->where('defects.data.0.defect_description', 'Без цены')
            );
    }

    #[Test]
    public function index_search_matches_product_sku(): void
    {
        $product = Product::factory()->create(['sku' => 'FIND-ME']);
        ProductDefect::factory()->for($product)->create();
        ProductDefect::factory()->create();

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects?search=FIND-ME')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('defects.data', 1));
    }

    // ────────────────────────────────────────────
    // Список «К отгрузке»
    // ────────────────────────────────────────────

    /**
     * Заказ уценки в заданном статусе с позицией на партию.
     */
    private function defectOrder(ProductDefect $defect, int $quantity, \App\Enums\OrderStatus $status): Order
    {
        $order = Order::factory()->create([
            'type' => OrderType::DEFECT,
            'status' => $status,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
            'defect_description' => $defect->defect_description,
            'name' => 'Уценка',
            'price' => 100,
            'base_price' => 100,
            'discount_percent' => 0,
            'final_price' => 100,
            'quantity' => $quantity,
            'subtotal' => 100 * $quantity,
        ]);

        return $order;
    }

    #[Test]
    public function shipping_lists_only_ready_for_shipment_defect_orders(): void
    {
        $ready = ProductDefect::factory()->sellable(100)->create(['quantity' => 5]);
        $pending = ProductDefect::factory()->sellable(100)->create(['quantity' => 5]);

        $this->defectOrder($ready, 2, \App\Enums\OrderStatus::READY_FOR_SHIPMENT);
        $this->defectOrder($pending, 1, \App\Enums\OrderStatus::PENDING_APPROVAL);

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects/shipping')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Defects/Shipping')
                ->has('orders.data', 1)
                ->where('orders.data.0.items.0.quantity', 2)
                ->where('orders.data.0.items.0.defect_description', $ready->defect_description)
                ->where('orders.data.0.items.0.defect_deleted', false)
            );
    }

    #[Test]
    public function shipping_shows_soft_deleted_defect_as_inactive(): void
    {
        // Партию мягко удаляют уже после формирования заказа — позиция должна
        // остаться в списке к отгрузке, но с флагом defect_deleted = true
        // (на фронте рисуется серой/disabled).
        $defect = ProductDefect::factory()->sellable(100)->create(['quantity' => 5]);
        $this->defectOrder($defect, 2, \App\Enums\OrderStatus::READY_FOR_SHIPMENT);

        $defect->delete();

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects/shipping')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Defects/Shipping')
                ->has('orders.data', 1)
                ->has('orders.data.0.items', 1)
                ->where('orders.data.0.items.0.quantity', 2)
                ->where('orders.data.0.items.0.defect_deleted', true)
            );
    }

    #[Test]
    public function shipping_ignores_regular_orders_in_ready_status(): void
    {
        // Обычный заказ «Готов к отгрузке» не должен попадать в список некондиции.
        Order::factory()->create([
            'type' => OrderType::ORDER,
            'status' => \App\Enums\OrderStatus::READY_FOR_SHIPMENT,
        ]);

        $this->actingAs($this->storekeeper())
            ->get('/wms/defects/shipping')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 0));
    }

    #[Test]
    public function shipping_requires_wms_defects_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/wms/defects/shipping')
            ->assertRedirect('/');
    }

    // ────────────────────────────────────────────
    // Быстрый приём
    // ────────────────────────────────────────────

    #[Test]
    public function resolve_barcode_finds_product_by_main_barcode(): void
    {
        $product = Product::factory()->create(['name' => 'Вибратор', 'barcode' => '4600000000017']);

        $response = $this->actingAs($this->storekeeper())
            ->getJson('/wms/defects/resolve-barcode?barcode=4600000000017')
            ->assertOk();

        $this->assertTrue($response->json('found'));
        $this->assertSame($product->id, $response->json('product.id'));
    }

    #[Test]
    public function resolve_barcode_returns_not_found_for_unknown(): void
    {
        $this->actingAs($this->storekeeper())
            ->getJson('/wms/defects/resolve-barcode?barcode=0000000000000')
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    #[Test]
    public function quick_store_creates_defect_with_photo(): void
    {
        $product = Product::factory()->create();
        $warehouse = $this->defectWarehouse();
        $storekeeper = $this->storekeeper();

        $this->actingAs($storekeeper)
            ->postJson('/wms/defects/quick', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Порвана упаковка',
                'quantity' => 3,
                'photos' => [\Illuminate\Http\UploadedFile::fake()->image('d.jpg')],
            ])
            ->assertCreated()
            ->assertJson(['success' => true]);

        $defect = ProductDefect::first();
        $this->assertSame(3, $defect->quantity);
        $this->assertSame($storekeeper->id, $defect->created_by);
        $this->assertCount(1, $defect->getMedia(ProductDefect::MEDIA_COLLECTION));
    }

    #[Test]
    public function quick_store_requires_photo(): void
    {
        $product = Product::factory()->create();
        $warehouse = $this->defectWarehouse();

        $this->actingAs($this->storekeeper())
            ->postJson('/wms/defects/quick', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'defect_description' => 'Царапины',
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');

        $this->assertSame(0, ProductDefect::count());
    }
}
