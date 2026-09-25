<?php

namespace Tests\Feature\Cabinet;

use App\Enums\OrderStatus;
use App\Enums\UserStatus;
use App\Events\OrderUpdated;
use App\Listeners\CaptureOrderStatusChanged;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Временный сайтовый номер заказа (ORD-…) клиенту не показывается.
 *
 * В 1С такого номера нет: клиенты не дожидались номера учётной системы,
 * цитировали ORD менеджеру, а тот не знал, где его искать. Пока 1С не
 * присвоила номер, клиент видит подсказку «будет присвоен при передаче
 * в учётную систему, обычно в течение ~N мин» — в кабинете, в клиентском
 * API и в письмах.
 */
class ClientOrderNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cabinet.order_number_eta_minutes' => 5]);

        $this->user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
        ]);
    }

    #[Test]
    public function model_gives_client_only_the_erp_number(): void
    {
        $pending = Order::factory()->create(['user_id' => $this->user->id, 'erp_number' => null]);
        $pending->created_at = '2026-09-18 10:00:00';

        $this->assertStringStartsWith('ORD-', $pending->number);
        $this->assertNull($pending->clientNumber());
        $this->assertTrue($pending->clientNumberPending());
        $this->assertSame('от 18.09.2026 (номер присваивается)', $pending->clientLabel());
        $this->assertSame(
            'Номер будет присвоен при передаче в учётную систему, обычно в течение ~5 мин.',
            Order::pendingNumberHint(),
        );

        $numbered = Order::factory()->create(['user_id' => $this->user->id, 'erp_number' => '29УТ-003413']);

        $this->assertSame('29УТ-003413', $numbered->clientNumber());
        $this->assertSame('29УТ-003413', $numbered->clientLabel());
        $this->assertSame(
            ['number' => '29УТ-003413', 'number_pending' => false, 'number_hint' => null],
            $numbered->clientNumberPayload(),
        );
    }

    #[Test]
    public function hint_period_comes_from_config(): void
    {
        config(['cabinet.order_number_eta_minutes' => 10]);

        $this->assertStringContainsString('~10 мин', Order::pendingNumberHint());
    }

    #[Test]
    public function cabinet_orders_list_hides_temporary_number_until_erp_assigns_one(): void
    {
        $pending = Order::factory()->create(['user_id' => $this->user->id, 'erp_number' => null]);
        $numbered = Order::factory()->create(['user_id' => $this->user->id, 'erp_number' => '29УТ-003413']);

        $this->actingAs($this->user)
            ->get('/cabinet/orders')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($pending, $numbered) {
                $orders = collect($page->toArray()['props']['orders']['data'])->keyBy('id');

                $this->assertNull($orders[$pending->id]['number']);
                $this->assertTrue($orders[$pending->id]['number_pending']);
                $this->assertStringContainsString('учётную систему', $orders[$pending->id]['number_hint']);

                $this->assertSame('29УТ-003413', $orders[$numbered->id]['number']);
                $this->assertFalse($orders[$numbered->id]['number_pending']);
                $this->assertNull($orders[$numbered->id]['number_hint']);

                $this->assertStringNotContainsString('ORD-', json_encode($orders, JSON_UNESCAPED_UNICODE));
            });
    }

    #[Test]
    public function cabinet_order_card_hides_temporary_number(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id, 'erp_number' => null]);

        $this->actingAs($this->user)
            ->get("/cabinet/orders/{$order->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Cabinet/Orders/Show')
                ->where('order.number', null)
                ->where('order.number_pending', true)
                ->where('order.number_hint', Order::pendingNumberHint()));
    }

    #[Test]
    public function cabinet_reserves_hide_temporary_number(): void
    {
        config(['order_reserve.enabled' => true]);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'erp_number' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/cabinet/reserves')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reserves.0.id', $order->id)
                ->where('reserves.0.number', null)
                ->where('reserves.0.number_pending', true));
    }

    #[Test]
    public function dashboard_recent_orders_show_label_instead_of_temporary_number(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id, 'erp_number' => null]);

        $this->actingAs($this->user)
            ->get('/cabinet/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentOrders.0.id', $order->id)
                ->where('recentOrders.0.order_number', null)
                ->where('recentOrders.0.order_label', $order->clientLabel()));
    }

    #[Test]
    public function client_api_reserves_give_null_number_with_hint(): void
    {
        config(['order_reserve.enabled' => true]);

        $this->user->forceFill(['reserve_allowed' => true])->save();
        $token = ApiToken::create(['user_id' => $this->user->id, 'name' => 'test', 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true,
            'reserved_until' => now()->addHours(20),
            'erp_number' => null,
        ]);

        $response = $this->getJson("/api/client-api/{$token->token}/reserves")->assertOk();

        $this->assertNull($response->json('reserves.0.number'));
        $this->assertTrue($response->json('reserves.0.number_pending'));
        $this->assertSame(Order::pendingNumberHint(), $response->json('reserves.0.number_hint'));
        $this->assertStringNotContainsString('ORD-', $response->getContent());
    }

    #[Test]
    public function status_letter_names_order_by_date_until_erp_assigns_number(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'erp_number' => null,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);
        $order->update(['status' => OrderStatus::READY_FOR_PROVISION]);

        $captured = null;
        $stream = $this->mock(MailStream::class);
        $stream->shouldReceive('captureQuietly')
            ->once()
            ->withArgs(function (Occasion $occasion) use (&$captured) {
                $captured = $occasion;

                return true;
            });

        (new CaptureOrderStatusChanged)->handle(new OrderUpdated($order));

        $this->assertNotNull($captured);
        $this->assertSame($order->clientLabel(), $captured->data['order_number']);
        $this->assertStringContainsString('номер присваивается', $captured->view['title']);
        $this->assertStringNotContainsString('ORD-', $captured->view['title']);
        $this->assertStringNotContainsString('ORD-', $captured->view['body']);
        // Ключ склейки писем остаётся внутренним номером — подписи «от даты» у разных заказов совпадают
        $this->assertSame($order->number, $captured->data['order_key']);
    }
}
