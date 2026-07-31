<?php

namespace Tests\Feature\Promotion;

use App\Contracts\Promotion\PromoStockServiceInterface;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Promotion\PromoStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Фонд промо-позиций (карточка promo-07).
 *
 * Ключевой инвариант: резерв — производная от заказов, а не хранимое поле,
 * поэтому удаление заказа обязано само возвращать товар в фонд.
 */
class PromoStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Region $region;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['name' => 'Основной']);
        $this->region = Region::factory()->create(['name' => 'Тестовый регион']);

        DB::table('region_warehouse')->insert([
            'region_id' => $this->region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create(['region_id' => $this->region->id]);
    }

    private function service(): PromoStockServiceInterface
    {
        return $this->app->make(PromoStockServiceInterface::class);
    }

    private function product(int $quantity): Product
    {
        $product = Product::factory()->create();

        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
        ]);

        return $product;
    }

    /**
     * @param  array<int, int>  $lines  product_id => quantity
     */
    private function promoOrder(array $lines, OrderType $type = OrderType::PROMO, OrderStatus $status = OrderStatus::PENDING_APPROVAL): Order
    {
        $company = Company::factory()->create(['user_id' => $this->user->id]);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'status' => $status,
            'type' => $type,
        ]);

        foreach ($lines as $productId => $quantity) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        return $order->refresh();
    }

    #[Test]
    public function без_заказов_доступен_весь_остаток_региона(): void
    {
        $product = $this->product(10);

        $this->assertSame(10, $this->service()->available($product, $this->user));
        $this->assertSame(0, $this->service()->reserved($product));
    }

    #[Test]
    public function промо_заказ_держит_резерв(): void
    {
        $product = $this->product(10);
        $this->promoOrder([$product->id => 4]);

        $this->assertSame(4, $this->service()->reserved($product));
        $this->assertSame(6, $this->service()->available($product, $this->user));
    }

    #[Test]
    public function удаление_заказа_возвращает_товар_в_фонд(): void
    {
        $product = $this->product(10);
        $order = $this->promoOrder([$product->id => 4]);

        $this->assertSame(6, $this->service()->available($product, $this->user));

        $order->delete(); // мягкое удаление

        $this->assertSame(0, $this->service()->reserved($product));
        $this->assertSame(10, $this->service()->available($product, $this->user));
    }

    #[Test]
    public function закрытый_заказ_резерв_не_держит(): void
    {
        $product = $this->product(10);
        $this->promoOrder([$product->id => 4], status: OrderStatus::CLOSED);

        $this->assertSame(0, $this->service()->reserved($product), 'Закрытый заказ отгружен — остаток уже уменьшила 1С');
        $this->assertSame(10, $this->service()->available($product, $this->user));
    }

    #[Test]
    public function обычный_заказ_фонд_не_трогает(): void
    {
        $product = $this->product(10);
        $this->promoOrder([$product->id => 4], type: OrderType::ORDER);

        $this->assertSame(0, $this->service()->reserved($product));
    }

    #[Test]
    public function рекламные_образцы_тоже_держат_резерв(): void
    {
        $product = $this->product(10);
        $this->promoOrder([$product->id => 3], type: OrderType::PROMO_SAMPLE);

        $this->assertSame(3, $this->service()->reserved($product));
    }

    #[Test]
    public function резерв_больше_остатка_даёт_ноль_а_не_отрицательное(): void
    {
        $product = $this->product(2);
        $this->promoOrder([$product->id => 5]);

        $this->assertSame(0, $this->service()->available($product, $this->user));
    }

    #[Test]
    public function товар_без_строки_в_pivot_это_ноль_а_не_исключение(): void
    {
        $product = Product::factory()->create();

        $this->assertSame(0, $this->service()->available($product, $this->user));
    }

    #[Test]
    public function перечень_резервирующих_статусов_покрывает_все_кроме_закрытого(): void
    {
        $expected = array_values(array_filter(
            OrderStatus::cases(),
            static fn (OrderStatus $status) => $status !== OrderStatus::CLOSED,
        ));

        $this->assertSame(
            $expected,
            PromoStockService::RESERVING_STATUSES,
            'Появился новый статус — решите явно, держит ли он промо-резерв',
        );
    }

    /**
     * Движок дёргает фонд на каждый рендер корзины, поэтому число запросов
     * не должно зависеть от количества проверяемых товаров.
     */
    #[Test]
    public function батч_на_50_товаров_укладывается_в_фиксированное_число_запросов(): void
    {
        $products = [];
        for ($i = 0; $i < 50; $i++) {
            $products[] = $this->product(10);
        }

        $this->promoOrder([$products[0]->id => 2, $products[1]->id => 3]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $map = $this->service()->availableMap($products, $this->user);

        $this->assertLessThanOrEqual(3, $queries, "Ожидали не больше 3 запросов, выполнено {$queries}");
        $this->assertCount(50, $map);
        $this->assertSame(8, $map[$products[0]->id]);
        $this->assertSame(7, $map[$products[1]->id]);
        $this->assertSame(10, $map[$products[2]->id]);
    }

    #[Test]
    public function пустой_список_товаров_не_ходит_в_базу(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertSame([], $this->service()->availableMap([], $this->user));
        $this->assertSame(0, $queries);
    }
}
