<?php

namespace Tests\Feature\Mail;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Orders\OrderStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderStatusChangedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.mail.features.order_status_changes' => true]);
    }

    public function test_erp_initiated_transition_to_whitelisted_status_sends_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);

        $order->fromErp = true;
        $order->status = OrderStatus::READY_FOR_SHIPMENT;
        $order->save();

        Notification::assertSentTo($user, OrderStatusChangedNotification::class, function ($n) use ($order) {
            return $n->order->is($order)
                && $n->order->status === OrderStatus::READY_FOR_SHIPMENT
                && $n->previousStatus === OrderStatus::PENDING_APPROVAL;
        });
    }

    public function test_manual_admin_change_does_not_send(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);

        // fromErp = false по умолчанию — это ручная правка админа
        $order->status = OrderStatus::SHIPPING;
        $order->save();

        Notification::assertNothingSent();
    }

    public function test_transition_outside_whitelist_does_not_send(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);

        $order->fromErp = true;
        $order->status = OrderStatus::READY_FOR_PROVISION;
        $order->save();

        Notification::assertNothingSent();
    }

    public function test_non_status_change_does_not_send(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING_APPROVAL,
            'comment' => 'old',
        ]);

        $order->fromErp = true;
        $order->comment = 'new comment';
        $order->save();

        Notification::assertNothingSent();
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.order_status_changes' => false]);

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING_APPROVAL,
        ]);

        $order->fromErp = true;
        $order->status = OrderStatus::SHIPPING;
        $order->save();

        Notification::assertNothingSent();
    }

    public function test_notification_subject_and_payload(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'number' => 'ORD-2026-0042',
            'status' => OrderStatus::SHIPPING,
        ]);

        $message = (new OrderStatusChangedNotification($order, OrderStatus::READY_FOR_SHIPMENT))
            ->toMail($user);

        $this->assertSame('Заказ ORD-2026-0042: В процессе отгрузки — Pecado.ru', $message->subject);
        $this->assertSame('mail.orders.status-changed', $message->markdown);
        $this->assertSame(OrderStatus::SHIPPING, $message->viewData['newStatus']);
        $this->assertSame(OrderStatus::READY_FOR_SHIPMENT, $message->viewData['previousStatus']);
        $this->assertStringContainsString('отгружен', $message->viewData['message']);
    }

    public function test_notification_renders_with_previous_status(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::CLOSED,
        ]);

        $rendered = (new OrderStatusChangedNotification($order, OrderStatus::READY_FOR_CLOSURE))
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('Здравствуйте, Иван', $rendered);
        $this->assertStringContainsString('Готов к закрытию', $rendered);
        $this->assertStringContainsString('Закрыт', $rendered);
        $this->assertStringContainsString('Спасибо, что выбираете Pecado.ru', $rendered);
    }

    public function test_notification_is_queueable(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new OrderStatusChangedNotification($order, OrderStatus::PENDING_APPROVAL),
        );
    }
}
