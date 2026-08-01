<?php

namespace Tests\Feature\Promotion;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Склад рекламных образцов «Москва подарки» (карточка promo-11).
 *
 * Главная проверка карточки: пробники не должны появиться на витрине.
 * Гарантия — невключение склада в регионы, но цена ошибки высока (пробник
 * продали бы как обычный товар), поэтому инвариант зафиксирован тестом,
 * а не устной договорённостью.
 */
class PromoSampleWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Region $region;

    private Warehouse $promoWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = Region::factory()->create(['name' => 'Тестовый регион']);

        $primary = Warehouse::factory()->create(['name' => 'Основной']);
        DB::table('region_warehouse')->insert([
            'region_id' => $this->region->id,
            'warehouse_id' => $primary->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Склад заводит миграция `2026_08_01_100500_set_promo_sample_warehouse_external_id`
        // с боевым UUID из 1С и намеренно без привязки к регионам. Берём его,
        // а не фабрику: тест должен судить о том, что реально приедет на прод
        $this->promoWarehouse = Warehouse::query()->promoSample()->firstOrFail();

        $this->user = User::factory()->create(['region_id' => $this->region->id]);
    }

    private function productOnPromoWarehouse(int $quantity = 50): Product
    {
        $product = Product::factory()->create(['base_price' => 500]);

        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->promoWarehouse->id,
            'quantity' => $quantity,
        ]);

        return $product;
    }

    #[Test]
    public function остаток_только_на_рекламном_складе_не_попадает_в_витринное_наличие(): void
    {
        $product = $this->productOnPromoWarehouse(50);

        $stock = app(StockServiceInterface::class)->getStock($product, $this->user);

        $this->assertSame(0, $stock['available'], 'Пробник не продаётся как товар в наличии');
        $this->assertSame(0, $stock['preorder'], 'И как предзаказ тоже');
    }

    #[Test]
    public function товар_с_рекламного_склада_не_кладётся_в_обычную_корзину(): void
    {
        $product = $this->productOnPromoWarehouse(50);
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        $result = app(CartServiceInterface::class)
            ->setProductQuantity($this->user, $cart, $product, 5);

        $this->assertSame(0, $result['clamped'], 'Доступное количество — ноль');
        $this->assertSame(0, $result['max_total']);
        $this->assertSame(0, $cart->fresh()->items()->count(), 'В корзине не должно появиться строки');
    }

    #[Test]
    public function рекламный_склад_не_входит_ни_в_один_регион(): void
    {
        $this->assertSame(0, $this->promoWarehouse->primaryRegions()->count());
        $this->assertSame(0, $this->promoWarehouse->preorderRegions()->count());
    }

    #[Test]
    public function скоуп_отбирает_только_рекламные_склады(): void
    {
        Warehouse::factory()->create(['name' => 'Москва некондиция', 'is_defect' => true]);

        $warehouses = Warehouse::query()->promoSample()->get();

        $this->assertCount(1, $warehouses);
        $this->assertSame('Москва подарки', $warehouses->first()->name);
        $this->assertSame('9da1768a-40d4-11e1-a692-001e6711ed1d', $warehouses->first()->external_id);
    }

    #[Test]
    public function склад_нельзя_сделать_одновременно_рекламным_и_некондиционным(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->post(route('admin.warehouses.store'), [
                'name' => 'Смешанный склад',
                'external_id' => null,
                'is_defect' => true,
                'is_promo_sample' => true,
            ])
            ->assertSessionHasErrors('is_promo_sample');

        $this->assertDatabaseMissing('warehouses', ['name' => 'Смешанный склад']);
    }

    #[Test]
    public function флаг_рекламного_склада_сохраняется_из_админки(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->post(route('admin.warehouses.store'), [
                'name' => 'Новосибирск реклама',
                'external_id' => 'wh-promo-sample-uuid',
                'is_defect' => false,
                'is_promo_sample' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Новосибирск реклама',
            'is_promo_sample' => true,
        ]);
    }
}
