<?php

namespace Tests\Feature\Order;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
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
 * v16.9.0, режим «Заказы в резерве» (res-06): «Поставьте в резерв» на чекауте.
 */
class CheckoutReserveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        config(['order_reserve.enabled' => true, 'order_reserve.hours' => 24]);
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

        $this->user = User::factory()->create(['region_id' => $region->id, 'reserve_allowed' => true]);
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function cartWithInstock(int $quantity = 2): Cart
    {
        $product = Product::factory()->create(['base_price' => 1000]);
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
        ]);

        $cart = Cart::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->base_price,
            'item_type' => 'instock',
        ]);

        return $cart->fresh();
    }

    private function checkout(Cart $cart, bool $reserve)
    {
        return app(CheckoutServiceInterface::class)->checkout(
            $cart,
            $this->company,
            'г. Москва, ул. Тестовая, д. 1',
            null,
            null,
            null,
            DeliveryMethod::DELIVERY,
            $reserve,
        );
    }

    #[Test]
    public function reserve_checkout_creates_reserved_order_and_publishes_flag(): void
    {
        $orders = $this->checkout($this->cartWithInstock(), reserve: true);

        $order = $orders->firstWhere('type', OrderType::ORDER);
        $this->assertNotNull($order);
        $this->assertTrue($order->reserve);
        $this->assertNotNull($order->reserved_until);
        $this->assertEqualsWithDelta(
            24 * 3600,
            $order->reserved_until->diffInSeconds(now(), true),
            60,
            'запрошенный срок — 24 часа из конфига',
        );

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) {
            return ($job->payload['reserve'] ?? false) === true
                && ! empty($job->payload['reserved_until']);
        });
    }

    #[Test]
    public function ordinary_checkout_is_untouched(): void
    {
        $orders = $this->checkout($this->cartWithInstock(), reserve: false);

        $order = $orders->firstWhere('type', OrderType::ORDER);
        $this->assertFalse((bool) $order->reserve);
        $this->assertNull($order->reserved_until);

        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) {
            return ! array_key_exists('reserve', $job->payload);
        });
    }

    #[Test]
    public function service_ignores_reserve_for_non_participant(): void
    {
        // Защитный гейт сервиса: обход валидации запроса не ставит резерв
        $this->user->update(['reserve_allowed' => false]);

        $orders = $this->checkout($this->cartWithInstock(), reserve: true);

        $this->assertFalse((bool) $orders->firstWhere('type', OrderType::ORDER)->reserve);
    }

    #[Test]
    public function http_checkout_rejects_reserve_for_non_participant(): void
    {
        $this->user->update(['reserve_allowed' => false]);
        $this->cartWithInstock();

        $this->actingAs($this->user)
            ->from('/checkout')
            ->post('/checkout', [
                'company_id' => $this->company->id,
                'delivery_method' => 'delivery',
                'delivery_address' => 'г. Москва, ул. Тестовая, д. 1',
                'reserve' => true,
            ])
            ->assertRedirect('/checkout')
            ->assertSessionHasErrors('reserve');
    }

    #[Test]
    public function http_reserve_checkout_redirects_to_order_page(): void
    {
        $this->cartWithInstock();

        $response = $this->actingAs($this->user)->post('/checkout', [
            'company_id' => $this->company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'г. Москва, ул. Тестовая, д. 1',
            'reserve' => true,
        ]);

        $order = \App\Models\Order::query()->where('user_id', $this->user->id)->firstOrFail();
        $this->assertTrue($order->reserve);
        $response->assertRedirect("/cabinet/orders/{$order->id}");
    }
}
