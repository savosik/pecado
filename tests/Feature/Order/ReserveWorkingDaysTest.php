<?php

namespace Tests\Feature\Order;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\ErpMessageValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * res-12 (v16.12.1, топик №11 Agent Hub): срок резерва в рабочих днях клиента —
 * интеграционно, от чекаута до сообщения order.created в шину.
 */
class ReserveWorkingDaysTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'order_reserve.enabled' => true,
            'order_reserve.hours' => 24,
            'order_reserve.working_days' => true,
            'order_reserve.hold_limit_hours' => 240,
            'production_calendar.holidays.2026' => ['2026-11-04', '2026-12-31'],
            'production_calendar.holidays.2027' => [
                '2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04',
                '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08',
            ],
        ]);
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

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->travelBack();

        parent::tearDown();
    }

    #[Test]
    public function friday_reserve_lives_until_monday_same_hour_and_message_passes_schema(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 17:00:00.750'));

        $order = $this->checkout(reserve: true)->firstWhere('type', OrderType::ORDER);

        $this->assertSame('2026-10-05 17:00:00', $order->reserved_until->format('Y-m-d H:i:s'));
        // Потолок 1С считается от date: created_at и срок — на одной секунде
        $this->assertSame('2026-10-02 17:00:00', $order->created_at->format('Y-m-d H:i:s'));

        $validator = app(ErpMessageValidator::class);
        Queue::assertPushed(PublishOrderToErpJob::class, function (PublishOrderToErpJob $job) use ($validator) {
            $payload = $job->payload;
            $date = CarbonImmutable::parse($payload['date']);
            $until = CarbonImmutable::parse($payload['reserved_until']);

            // Ограничитель 1С: min(reserved_until, date + 240 ч) — срок не должен резаться
            return $payload['reserve'] === true
                && $until->format('Y-m-d H:i') === '2026-10-05 17:00'
                && $date->format('Y-m-d H:i:s') === '2026-10-02 17:00:00'
                && $date->diffInSeconds($until) <= 240 * 3600
                && $validator->validateOutbound('order.created', $payload)['valid'] === true;
        });
    }

    #[Test]
    public function reserve_is_refused_across_new_year_and_no_order_is_placed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-30 17:00:00'));

        try {
            $this->checkout(reserve: true);
            $this->fail('резерв через каникулы должен быть отклонён');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('новогодние', $e->errors()['reserve'][0]);
        }

        // Никакой тихой конвертации в заказ под отгрузку
        $this->assertSame(0, Order::query()->count());
        Queue::assertNotPushed(PublishOrderToErpJob::class);

        // Обычный заказ в те же дни оформляется штатно
        $order = $this->checkout(reserve: false)->firstWhere('type', OrderType::ORDER);
        $this->assertFalse((bool) $order->reserve);
    }

    #[Test]
    public function friday_reserve_is_not_released_on_weekend(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 17:00:00'));
        $order = $this->checkout(reserve: true)->firstWhere('type', OrderType::ORDER);

        foreach (['2026-10-03 17:30:00', '2026-10-04 23:59:00', '2026-10-05 16:59:00'] as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment));
            $this->artisan('reserve:release-expired')->assertSuccessful();

            $this->assertTrue((bool) $order->fresh()->reserve, "резерв жив в {$moment}");
        }
    }

    #[Test]
    public function shared_props_show_deadline_instead_of_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 17:00:00'));

        $this->actingAs($this->user)
            ->get('/cabinet/orders')
            ->assertInertia(fn ($page) => $page
                ->where('config.reserve_until_text', 'пн, 5 октября, 17:00')
                ->where('config.reserve_block_reason', null));
    }

    #[Test]
    public function calendar_hours_are_kept_while_switch_is_off(): void
    {
        config(['order_reserve.working_days' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 17:00:00'));

        $order = $this->checkout(reserve: true)->firstWhere('type', OrderType::ORDER);

        $this->assertSame('2026-10-03 17:00:00', $order->reserved_until->format('Y-m-d H:i:s'));
    }

    private function checkout(bool $reserve)
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
            'quantity' => 2,
            'price' => $product->base_price,
            'item_type' => 'instock',
        ]);

        return app(CheckoutServiceInterface::class)->checkout(
            $cart->fresh(),
            $this->company,
            'г. Москва, ул. Тестовая, д. 1',
            null,
            null,
            null,
            DeliveryMethod::DELIVERY,
            $reserve,
        );
    }
}
