<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDefectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Закупщик: заводим роль как на prod (вручную) + доназначаем defects.*,
     * что делает миграция grant_defect_permissions.
     */
    private function buyer(): User
    {
        $role = Role::firstOrCreate(['name' => 'buyer-manager', 'guard_name' => 'web']);

        foreach (['products.view', 'defects.view', 'defects.price', 'defects.publish'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Закупщик только с просмотром — без прав на цену и публикацию. */
    private function viewerOnly(): User
    {
        $role = Role::firstOrCreate(['name' => 'defects-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'defects.view', 'guard_name' => 'web']));
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'products.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function buyer_can_open_defects_list(): void
    {
        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Pages/Defects/Index'));
    }

    #[Test]
    public function user_without_defect_permission_cannot_open_list(): void
    {
        $user = User::factory()->create();
        $user->assignRole('content-manager');

        $this->actingAs($user)->get('/admin/defects')->assertForbidden();
    }

    #[Test]
    public function buyer_sets_price_and_records_author(): void
    {
        $buyer = $this->buyer();
        $defect = ProductDefect::factory()->create();

        $this->actingAs($buyer)
            ->put("/admin/defects/{$defect->id}/price", ['price' => 499.90])
            ->assertRedirect();

        $defect->refresh();

        $this->assertSame('499.90', $defect->price);
        $this->assertSame($buyer->id, $defect->priced_by);
    }

    #[Test]
    public function price_must_be_positive(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/price", ['price' => 0])
            ->assertSessionHasErrors('price');

        $this->assertNull($defect->fresh()->price);
    }

    #[Test]
    public function defect_cannot_be_published_without_price(): void
    {
        $defect = ProductDefect::factory()->create(['price' => null]);

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true])
            ->assertSessionHas('error');

        $this->assertFalse($defect->fresh()->is_published);
    }

    #[Test]
    public function priced_defect_can_be_published_and_unpublished(): void
    {
        $defect = ProductDefect::factory()->priced(300)->create();

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true])
            ->assertRedirect();

        $this->assertTrue($defect->fresh()->is_published);

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => false])
            ->assertRedirect();

        $this->assertFalse($defect->fresh()->is_published);
    }

    #[Test]
    public function published_defect_becomes_sellable(): void
    {
        $defect = ProductDefect::factory()->priced(300)->create();

        $this->assertSame(0, ProductDefect::query()->sellable()->count());

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true]);

        $this->assertSame(1, ProductDefect::query()->sellable()->count());
    }

    #[Test]
    public function viewer_without_price_permission_is_forbidden_to_set_price(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->actingAs($this->viewerOnly())
            ->put("/admin/defects/{$defect->id}/price", ['price' => 100])
            ->assertForbidden();
    }

    #[Test]
    public function viewer_without_publish_permission_is_forbidden_to_publish(): void
    {
        $defect = ProductDefect::factory()->priced(100)->create();

        $this->actingAs($this->viewerOnly())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true])
            ->assertForbidden();
    }

    #[Test]
    public function closed_defect_price_cannot_be_changed(): void
    {
        $defect = ProductDefect::factory()->priced(200)->closed()->create();

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/price", ['price' => 999])
            ->assertSessionHas('error');

        $this->assertSame('200.00', $defect->fresh()->price);
    }

    #[Test]
    public function index_filters_published_only(): void
    {
        ProductDefect::factory()->create();
        ProductDefect::factory()->sellable(100)->create(['defect_description' => 'Опубликованная']);

        $this->actingAs($this->buyer())
            ->get('/admin/defects?filter=published')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('defects.data', 1)
                ->where('defects.data.0.defect_description', 'Опубликованная')
            );
    }

    /** Остаток из 1С по складу некондиции. */
    private function stock(Product $product, Warehouse $warehouse, int $quantity): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Остаток 1С — по всей позиции, «заведено складом» — по партии. Два разных
     * числа: на один остаток кладовщик может завести несколько партий.
     */
    #[Test]
    public function index_shows_erp_stock_and_warehouse_quantity_separately(): void
    {
        $product = Product::factory()->create(['code' => '0T-00009461']);
        $warehouse = Warehouse::factory()->defect()->create();

        $this->stock($product, $warehouse, 5);

        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
        ]);
        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);

        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('defects.data', 2)
                ->where('defects.data.0.erp_stock_quantity', 5)
                ->where('defects.data.0.covered_quantity', 3)
                ->where('defects.data.0.uncovered_quantity', 2)
                ->where('defects.data.0.quantity', 1)
                ->where('defects.data.1.quantity', 2)
                ->where('defects.data.0.product.code', '0T-00009461')
            );
    }

    /** Партий заведено больше, чем числится в 1С, — расхождение уходит в минус. */
    #[Test]
    public function index_reports_negative_uncovered_when_batches_exceed_erp_stock(): void
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->defect()->create();

        $this->stock($product, $warehouse, 1);

        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 4,
        ]);

        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defects.data.0.erp_stock_quantity', 1)
                ->where('defects.data.0.uncovered_quantity', -3)
            );
    }

    /**
     * Выгрузка: реквизиты товара и обе величины остатка, по текущему фильтру и
     * по всем строкам отбора, а не по видимой странице.
     */
    #[Test]
    public function export_returns_xlsx_with_product_details_and_quantities(): void
    {
        $product = Product::factory()->create([
            'code' => '0T-00009461',
            'sku' => '741201',
            'name' => 'Вибратор для точки G',
        ]);
        $warehouse = Warehouse::factory()->defect()->create(['name' => 'Некондиция']);

        $this->stock($product, $warehouse, 5);

        ProductDefect::factory()->priced(300)->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'defect_description' => 'Порвана упаковка',
        ]);

        // Закрытая партия в фильтр «открытые» попадать не должна.
        ProductDefect::factory()->closed()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'defect_description' => 'Списанная партия',
        ]);

        $response = $this->actingAs($this->buyer())->get('/admin/defects/export?filter=open');

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));

        $rows = $this->readSheet($response->streamedContent());

        $this->assertSame(
            ['Партия №', 'Код 1С', 'Артикул', 'Товар', 'Склад', 'Дефект', 'Свободно 1С'],
            array_slice($rows[0], 0, 7)
        );
        $this->assertCount(2, $rows, 'В выгрузке должны быть заголовок и одна открытая партия.');

        $row = $rows[1];

        $this->assertSame('0T-00009461', $row[1]);
        $this->assertSame('741201', $row[2]);
        $this->assertSame('Вибратор для точки G', $row[3]);
        $this->assertSame('Некондиция', $row[4]);
        $this->assertSame('Порвана упаковка', $row[5]);
        $this->assertSame(5, (int) $row[6], 'Свободно 1С');
        $this->assertSame(2, (int) $row[7], 'Разобрано партиями');
        $this->assertSame(3, (int) $row[8], 'Не разобрано');
        $this->assertSame(2, (int) $row[9], 'Заведено складом');
        $this->assertSame(300.0, (float) $row[14], 'Цена уценки');
    }

    #[Test]
    public function export_is_forbidden_without_defect_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('content-manager');

        $this->actingAs($user)->get('/admin/defects/export')->assertForbidden();
    }

    /**
     * Содержимое первого листа XLSX построчно.
     *
     * @return array<int, array<int, mixed>>
     */
    private function readSheet(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'defects').'.xlsx';
        file_put_contents($path, $binary);

        try {
            return IOFactory::load($path)->getActiveSheet()->toArray();
        } finally {
            @unlink($path);
        }
    }
}
