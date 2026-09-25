<?php

namespace Tests\Feature\Erp;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Enums\DeliveryMethod;
use App\Exceptions\ProductWithoutPriceException;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\ErpMessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Нулевая базовая цена в позиции обычного заказа (разбор 23.09.2026,
 * заказы 29УТ-014790 и 29УТ-014791, артикул 955067-L).
 *
 * Карточка товара приезжает из 1С сообщением product.created с нулевой базовой
 * ценой, а price.updated по ней может не прийти никогда. Продаётся товар при
 * этом по индивидуальной цене из выгрузки. Для обычной позиции контракт задаёт
 * тождество final_price = base_price × (1 − discount_percent / 100), поэтому
 * пара «base_price 0 / final_price 1212» давала в 1С строку с нулевой ценой и
 * непроводимый документ.
 *
 * Правило проекта: всё, что идёт через RabbitMQ и отражено в AsyncAPI,
 * покрывается интеграционными тестами.
 */
class OrderZeroBasePriceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create([
            'name' => 'Москва Основной',
            'external_id' => 'wh-primary-uuid',
        ]);

        $region = Region::factory()->create(['name' => 'Тестовый регион']);
        DB::table('region_warehouse')->insert([
            'region_id' => $region->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create([
            'region_id' => $region->id,
            'erp_id' => 'partner-uuid',
        ]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function product(float $basePrice, ?float $individualPrice = null): Product
    {
        $product = Product::factory()->create([
            'base_price' => $basePrice,
            'external_id' => 'product-'.uniqid(),
        ]);

        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 57,
        ]);

        if ($individualPrice !== null) {
            IndividualPrice::create([
                'partner_id' => $this->user->id,
                'product_id' => $product->id,
                'warehouse_id' => $this->warehouse->id,
                'price' => $individualPrice,
            ]);
        }

        return $product;
    }

    /**
     * @param  array<int, Product>  $products
     */
    private function checkout(array $products)
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);

        foreach ($products as $product) {
            CartItem::factory()->create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $product->base_price,
                'item_type' => 'instock',
            ]);
        }

        return app(CheckoutServiceInterface::class)->checkout(
            $cart->fresh(),
            $this->company,
            null,
            null,
            null,
            null,
            DeliveryMethod::PICKUP,
        );
    }

    private function payloadOf(PublishOrderToErpJob $job): array
    {
        $property = new \ReflectionProperty($job, 'payload');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    #[Test]
    public function товар_без_прайсовой_цены_уезжает_с_базой_равной_цене_клиента(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        // Размер M: и прайсовая цена, и индивидуальная — эталон обычной строки
        $withBase = $this->product(2020.00, 1212.00);
        // Размер L: price.updated не приходил, прайсовой цены нет
        $withoutBase = $this->product(0.00, 1212.00);

        $orders = $this->checkout([$withBase, $withoutBase]);

        $this->assertCount(1, $orders);
        // Итог заказа считается по цене клиента и от нулевой базы не зависит
        $this->assertSame(2424.00, (float) $orders->first()->total_amount);

        $payload = null;
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$payload) {
            $payload = $this->payloadOf($job);

            return true;
        });

        $lines = collect($payload['items'])->keyBy('product_uuid');

        $normal = $lines[$withBase->external_id];
        $this->assertSame(2020.0, $normal['base_price']);
        $this->assertSame(40.0, $normal['discount_percent']);
        $this->assertSame(1212.0, $normal['final_price']);

        $patched = $lines[$withoutBase->external_id];
        $this->assertSame(1212.0, $patched['base_price'], 'Нулевая база схлопывается в цену клиента');
        $this->assertSame(0.0, $patched['discount_percent']);
        $this->assertSame(1212.0, $patched['final_price']);

        // Тождество обычной позиции соблюдено по всем строкам: именно его
        // нарушение и делало документ в 1С непроводимым
        foreach ($payload['items'] as $line) {
            $this->assertSame(
                round($line['base_price'] * (1 - $line['discount_percent'] / 100), 2),
                round($line['final_price'], 2),
                'final_price = base_price × (1 − discount_percent / 100)',
            );
            $this->assertGreaterThan(0, $line['base_price']);
        }

        $validation = app(ErpMessageValidator::class)->validateOutbound('order.created', $payload);
        $this->assertTrue($validation['valid'], implode('; ', $validation['errors']));
    }

    #[Test]
    public function наценка_тоже_уезжает_согласованной_парой(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        // Индивидуальная цена выше прайсовой: discount_percent обрезается до 0,
        // и пара «база 1000 / цена 1200» посчиталась бы в 1С по базе
        $product = $this->product(1000.00, 1200.00);

        $this->checkout([$product]);

        $payload = null;
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use (&$payload) {
            $payload = $this->payloadOf($job);

            return true;
        });

        $line = $payload['items'][0];
        $this->assertSame(1200.0, $line['base_price']);
        $this->assertSame(0.0, $line['discount_percent']);
        $this->assertSame(1200.0, $line['final_price']);
    }

    #[Test]
    public function позиция_без_цены_вообще_не_оформляется(): void
    {
        Queue::fake([PublishOrderToErpJob::class]);

        $priceless = $this->product(0.00);

        try {
            $this->checkout([$priceless]);
            $this->fail('Позиция без цены не должна проходить оформление');
        } catch (ProductWithoutPriceException $e) {
            $this->assertSame(
                [$priceless->id],
                array_column($e->getItems(), 'product_id'),
            );
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }
}
