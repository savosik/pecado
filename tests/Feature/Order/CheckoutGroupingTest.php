<?php

namespace Tests\Feature\Order;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Enums\DeliveryMethod;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Группировка документов одного оформления.
 *
 * Документы связывались по `cart_id`, но корзина живёт долго и переиспользуется:
 * в кабинете это давало «Одно оформление · документов: 10» — в группу слипалась
 * вся история корзины. Теперь связь несёт `checkout_uuid`, общий на сборку.
 */
class CheckoutGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOrderToErpJob::class]);

        $this->warehouse = Warehouse::factory()->create(['name' => 'Основной']);

        $region = Region::factory()->create(['name' => 'Тестовый регион']);
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create(['region_id' => $region->id]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function product(int $available, int $preorder = 0): Product
    {
        $product = Product::factory()->create(['base_price' => 1000]);

        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $available,
        ]);

        if ($preorder > 0) {
            $preorderWarehouse = Warehouse::factory()->create(['name' => 'Предзаказный']);

            DB::table('region_warehouse')->insert([
                'region_id' => $this->user->region_id,
                'warehouse_id' => $preorderWarehouse->id,
                'type' => 'preorder',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('product_warehouse')->insert([
                'product_id' => $product->id,
                'warehouse_id' => $preorderWarehouse->id,
                'quantity' => $preorder,
            ]);
        }

        return $product;
    }

    /**
     * Чекаут из указанной корзины. Корзину передаём явно — весь смысл теста
     * в том, что одна и та же корзина оформляется несколько раз.
     */
    private function checkout(Cart $cart, Product $product, int $quantity, string $itemType = 'instock')
    {
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->base_price,
            'item_type' => $itemType,
        ]);

        return app(CheckoutServiceInterface::class)->checkout(
            $cart->fresh(),
            $this->company,
            'г. Москва, ул. Тестовая, д. 1',
            null,
            null,
            null,
            DeliveryMethod::DELIVERY,
        );
    }

    #[Test]
    public function документы_одной_сборки_несут_общий_идентификатор(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = $this->product(5, 5);

        // Наличие + предзаказ: одно оформление, два документа. Строки уже
        // разложены по item_type — так их кладёт в корзину CartService
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => $product->base_price,
            'item_type' => 'preorder',
        ]);

        $orders = $this->checkout($cart, $product, 3);

        $this->assertCount(2, $orders);

        $uuids = $orders->pluck('checkout_uuid')->unique();

        $this->assertCount(1, $uuids, 'На всю сборку должен быть один checkout_uuid');
        $this->assertNotNull($uuids->first());
    }

    /**
     * Тот самый баг: корзина после чекаута не исчезает, и следующее оформление
     * идёт из неё же. По cart_id все документы за месяцы оказывались в одной
     * «покупке».
     */
    #[Test]
    public function повторное_оформление_той_же_корзины_даёт_новую_группу(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        $first = $this->checkout($cart, $this->product(10), 3);

        $cart->items()->delete();

        $second = $this->checkout($cart->fresh(), $this->product(10), 2);

        $this->assertSame(
            $first->first()->cart_id,
            $second->first()->cart_id,
            'Предусловие теста: корзина та же самая',
        );

        $this->assertNotSame(
            $first->first()->checkout_uuid,
            $second->first()->checkout_uuid,
            'Это разные покупки — группы должны быть разными',
        );
    }

    #[Test]
    public function кабинет_отдаёт_идентификатор_оформления(): void
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        $product = $this->product(5, 5);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => $product->base_price,
            'item_type' => 'preorder',
        ]);

        $orders = $this->checkout($cart, $product, 3);

        $expected = $orders->first()->checkout_uuid;

        $this->actingAs($this->user)
            ->get(route('cabinet.orders.index'))
            ->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use ($expected) {
                $rows = collect($page->toArray()['props']['orders']['data']);

                $this->assertCount(2, $rows);
                $this->assertSame([$expected], $rows->pluck('checkout_uuid')->unique()->all());
            });
    }

    /**
     * Заказ, приехавший из 1С, оформления на сайте не имел — группировать нечего,
     * и заголовок группы над ним рисоваться не должен.
     */
    #[Test]
    public function заказ_из_1с_остаётся_без_идентификатора(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'cart_id' => null,
        ]);

        $this->assertNull($order->checkout_uuid);
    }
}
