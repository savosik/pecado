<?php

namespace Tests\Feature\Wms;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Defect\DefectCoverageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Отчёт «Не закрыто партиями»: остатки склада некондиции из 1С против
 * заведённых кладовщиком партий брака.
 */
class WmsDefectCoverageTest extends TestCase
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

    /** Живой заказ уценки на партию: резерв, который 1С уже вычла из остатка. */
    private function reserve(ProductDefect $defect, int $quantity): Order
    {
        $order = Order::factory()->create(['type' => OrderType::DEFECT]);
        $order->items()->create([
            'product_id' => $defect->product_id,
            'product_defect_id' => $defect->id,
            'name' => 'Резерв',
            'price' => 300,
            'base_price' => 300,
            'discount_percent' => 0,
            'final_price' => 300,
            'quantity' => $quantity,
            'subtotal' => 300 * $quantity,
        ]);

        return $order;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $query = ''): array
    {
        $response = $this->actingAs($this->storekeeper())
            ->get('/wms/defects/uncovered'.($query !== '' ? '?'.$query : ''));

        $response->assertOk();

        return $response->viewData('page')['props']['rows']['data'];
    }

    // ────────────────────────────────────────────
    // Доступ
    // ────────────────────────────────────────────

    #[Test]
    public function отчёт_закрыт_от_сотрудника_без_права_склада(): void
    {
        // middleware 'wms' уводит постороннего на витрину, а не отдаёт 403.
        $this->actingAs(User::factory()->create())
            ->get('/wms/defects/uncovered')
            ->assertRedirect('/');
    }

    #[Test]
    public function кладовщик_видит_отчёт(): void
    {
        $this->actingAs($this->storekeeper())
            ->get('/wms/defects/uncovered')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Defects/Uncovered')
                ->has('rows.data')
                ->has('stats')
                ->has('warehouses')
            );
    }

    // ────────────────────────────────────────────
    // Расчёт покрытия
    // ────────────────────────────────────────────

    #[Test]
    public function остаток_без_партий_попадает_в_отчёт_целиком(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create(['sku' => 'ART-100']);
        $this->stock($product, $warehouse, 7);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('ART-100', $rows[0]['product_sku']);
        $this->assertSame(7, $rows[0]['stock_quantity']);
        $this->assertSame(0, $rows[0]['covered_quantity']);
        $this->assertSame(7, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function частично_заведённая_партия_уменьшает_непокрытый_остаток(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 10);

        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 4,
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['covered_quantity']);
        $this->assertSame(6, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function полностью_покрытый_остаток_из_отчёта_уходит(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 3);

        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);

        $this->assertSame([], $this->rows());

        // Но в режиме «Все позиции» строка видна с нулём.
        $all = $this->rows('filter=all');
        $this->assertCount(1, $all);
        $this->assertSame(0, $all[0]['uncovered_quantity']);
    }

    #[Test]
    public function несколько_партий_суммируются(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 10);

        ProductDefect::factory()->count(2)->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);

        $rows = $this->rows();

        $this->assertSame(6, $rows[0]['covered_quantity']);
        $this->assertSame(2, $rows[0]['batches_count']);
        $this->assertSame(4, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function закрытая_партия_покрытием_не_считается(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 5);

        ProductDefect::factory()->closed()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function удалённая_партия_покрытием_не_считается(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 5);

        $defect = ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
        ]);
        $defect->delete();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function партия_без_цены_покрывает_остаток_но_считается_отдельно(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 5);

        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
        ]);
        ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);

        $rows = $this->rows();

        $this->assertSame(3, $rows[0]['covered_quantity']);
        $this->assertSame(2, $rows[0]['idle_quantity']);
        $this->assertSame(2, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function остатки_разных_складов_не_смешиваются(): void
    {
        $first = Warehouse::factory()->defect()->create(['name' => 'Некондиция Тюмень']);
        $second = Warehouse::factory()->defect()->create(['name' => 'Некондиция Москва']);
        $product = Product::factory()->create();

        $this->stock($product, $first, 4);
        $this->stock($product, $second, 6);

        // Партия на первом складе остаток второго не закрывает.
        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $first->id,
            'quantity' => 4,
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Некондиция Москва', $rows[0]['warehouse_name']);
        $this->assertSame(6, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function обычный_склад_в_отчёт_не_попадает(): void
    {
        $warehouse = Warehouse::factory()->create(['is_defect' => false]);
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 15);

        $this->assertSame([], $this->rows('filter=all'));
    }

    #[Test]
    public function нулевой_остаток_без_партий_в_отчёт_не_попадает(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 0);

        $this->assertSame([], $this->rows('filter=all'));
    }

    // ────────────────────────────────────────────
    // Расхождения
    // ────────────────────────────────────────────

    #[Test]
    public function партий_больше_остатка_видно_в_расхождениях(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();
        $this->stock($product, $warehouse, 1);

        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 4,
        ]);

        // В основном режиме такой позиции нет — непокрытого остатка у неё нет.
        $this->assertSame([], $this->rows());

        $rows = $this->rows('filter=over');
        $this->assertCount(1, $rows);
        $this->assertSame(-3, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function партия_без_остатка_в_1с_видна_как_расхождение(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
        ]);

        $rows = $this->rows('filter=over');

        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['stock_quantity']);
        $this->assertSame(-2, $rows[0]['uncovered_quantity']);
    }

    // ────────────────────────────────────────────
    // Резерв: 1С шлёт свободный остаток, партии описывают полку
    // ────────────────────────────────────────────

    #[Test]
    public function резерв_живого_заказа_вычитается_из_партий(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        // Полка: 3 шт. в партиях, 2 уже заказаны → 1С прислала 1 свободную.
        $this->stock($product, $warehouse, 1);
        $defect = ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);
        $this->reserve($defect, 2);

        // Расхождения нет: свободно в 1С 1, свободно в партиях 3 − 2 = 1.
        $this->assertSame([], $this->rows('filter=over'));

        $rows = $this->rows('filter=all');
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['stock_quantity']);
        $this->assertSame(3, $rows[0]['covered_quantity']);
        $this->assertSame(2, $rows[0]['reserved_quantity']);
        $this->assertSame(1, $rows[0]['free_quantity']);
        $this->assertSame(0, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function резерв_удалённого_заказа_не_считается(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        // Заказ отменили: 1С вернула остаток к 3, сайт снял заказ soft-delete-ом.
        $this->stock($product, $warehouse, 3);
        $defect = ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);
        $this->reserve($defect, 2)->delete();

        $rows = $this->rows('filter=all');
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['reserved_quantity']);
        $this->assertSame(3, $rows[0]['free_quantity']);
        $this->assertSame(0, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function строка_отменённая_1с_при_недоборе_резерв_не_держит(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        // 1С отменила строку заказа (недобор) и вернула остаток к 3; строка в
        // заказе осталась с количеством, но с флагом cancelled.
        $this->stock($product, $warehouse, 3);
        $defect = ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);
        $this->reserve($defect, 2)->items()->update(['cancelled' => true]);

        $rows = $this->rows('filter=all');
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['reserved_quantity']);
        $this->assertSame(0, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function отгруженный_но_не_закрывший_партию_заказ_остаётся_занятым(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        // Партия 3, заказ на 2 отгружен: полка 1, 1С свободно 1. Партия открыта,
        // потому что отгружено не всё, — уехавшие 2 всё ещё сидят в её объёме.
        $this->stock($product, $warehouse, 1);
        $defect = ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);
        $this->reserve($defect, 2)->update(['status' => \App\Enums\OrderStatus::CLOSED]);

        $rows = $this->rows('filter=all');
        $this->assertSame(2, $rows[0]['reserved_quantity']);
        $this->assertSame(1, $rows[0]['free_quantity']);
        $this->assertSame(0, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function резерв_закрытой_партии_второй_раз_не_вычитается(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        // После реализации: партия на 2 закрыта как распроданная, заказ на неё
        // жив (реализация состоялась), открыта одна партия на 1. В 1С свободно 1.
        $this->stock($product, $warehouse, 1);
        $sold = ProductDefect::factory()->closed()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
        ]);
        $this->reserve($sold, 2);
        ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);

        $rows = $this->rows('filter=all');
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['covered_quantity']);
        $this->assertSame(0, $rows[0]['reserved_quantity']);
        $this->assertSame(0, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function резерв_партии_ограничен_её_объёмом(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        // Заказали больше, чем в партии, — свободного меньше нуля не бывает.
        $this->stock($product, $warehouse, 0);
        $defect = ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);
        $this->reserve($defect, 3);

        $rows = $this->rows('filter=all');
        $this->assertSame(1, $rows[0]['reserved_quantity']);
        $this->assertSame(0, $rows[0]['free_quantity']);
        $this->assertSame(0, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function заказ_который_1с_ещё_не_учла_виден_как_непокрытый_остаток(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        // Окно между оформлением на сайте и пересчётом в 1С: 1С ещё шлёт 3,
        // сайт уже держит 2 в заказе. Это не расхождение, а непокрытые 2 шт.
        $this->stock($product, $warehouse, 3);
        $defect = ProductDefect::factory()->sellable()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);
        $this->reserve($defect, 2);

        $this->assertSame([], $this->rows('filter=over'));

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['uncovered_quantity']);
    }

    // ────────────────────────────────────────────
    // Фильтры и сводка
    // ────────────────────────────────────────────

    #[Test]
    public function поиск_работает_по_артикулу(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $needle = Product::factory()->create(['sku' => 'ART-777', 'name' => 'Первый']);
        $other = Product::factory()->create(['sku' => 'ART-888', 'name' => 'Второй']);

        $this->stock($needle, $warehouse, 2);
        $this->stock($other, $warehouse, 3);

        $rows = $this->rows('search=ART-777');

        $this->assertCount(1, $rows);
        $this->assertSame('ART-777', $rows[0]['product_sku']);
    }

    #[Test]
    public function фильтр_по_складу_ограничивает_выдачу(): void
    {
        $first = Warehouse::factory()->defect()->create();
        $second = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create();

        $this->stock($product, $first, 2);
        $this->stock($product, $second, 3);

        $rows = $this->rows('warehouse_id='.$second->id);

        $this->assertCount(1, $rows);
        $this->assertSame($second->id, $rows[0]['warehouse_id']);
        $this->assertSame(3, $rows[0]['uncovered_quantity']);
    }

    #[Test]
    public function сводка_считает_позиции_штуки_и_расхождения(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();

        // Непокрытый остаток: 10 шт.
        $first = Product::factory()->create();
        $this->stock($first, $warehouse, 10);

        // Непокрыто 2 шт., ещё 3 шт. заведены, но без цены.
        $second = Product::factory()->create();
        $this->stock($second, $warehouse, 5);
        ProductDefect::factory()->create([
            'product_id' => $second->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]);

        // Расхождение: партий больше остатка.
        $third = Product::factory()->create();
        $this->stock($third, $warehouse, 1);
        ProductDefect::factory()->sellable()->create([
            'product_id' => $third->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
        ]);

        // Не расхождение: партий 3, из них 2 в заказе, в 1С свободно 1.
        $fourth = Product::factory()->create();
        $this->stock($fourth, $warehouse, 1);
        $this->reserve(ProductDefect::factory()->sellable()->create([
            'product_id' => $fourth->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
        ]), 2);

        $stats = app(DefectCoverageService::class)->stats();

        $this->assertSame(2, $stats['uncovered_positions']);
        $this->assertSame(12, $stats['uncovered_units']);
        $this->assertSame(1, $stats['over_positions']);
        $this->assertSame(3, $stats['idle_units']);
    }

    #[Test]
    public function сортировка_ставит_наибольший_непокрытый_остаток_первым(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $small = Product::factory()->create(['sku' => 'SMALL']);
        $big = Product::factory()->create(['sku' => 'BIG']);

        $this->stock($small, $warehouse, 2);
        $this->stock($big, $warehouse, 40);

        $rows = $this->rows();

        $this->assertSame('BIG', $rows[0]['product_sku']);
        $this->assertSame('SMALL', $rows[1]['product_sku']);
    }

    // ────────────────────────────────────────────
    // Переход к заведению партии
    // ────────────────────────────────────────────

    #[Test]
    public function форма_создания_предзаполняется_товаром_и_складом_из_отчёта(): void
    {
        $warehouse = Warehouse::factory()->defect()->create();
        $product = Product::factory()->create(['sku' => 'ART-555']);

        $this->actingAs($this->storekeeper())
            ->get("/wms/defects/create?product_id={$product->id}&warehouse_id={$warehouse->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Wms/Pages/Defects/Create')
                ->where('prefill.product.id', $product->id)
                ->where('prefill.product.sku', 'ART-555')
                ->where('prefill.warehouse_id', $warehouse->id)
            );
    }

    #[Test]
    public function обычный_склад_в_предзаполнение_не_проходит(): void
    {
        $warehouse = Warehouse::factory()->create(['is_defect' => false]);
        $product = Product::factory()->create();

        $this->actingAs($this->storekeeper())
            ->get("/wms/defects/create?product_id={$product->id}&warehouse_id={$warehouse->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prefill.warehouse_id', 0)
            );
    }
}
