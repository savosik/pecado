<?php

namespace Tests\Feature\Mail;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\Orders\OrderCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderCreatedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.mail.features.order_created' => true]);
    }

    public function test_dispatching_order_created_sends_notification_to_client(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'number' => 'ORD-2026-0001',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Тестовый товар',
            'quantity' => 2,
            'price' => 150.00,
            'subtotal' => 300.00,
        ]);

        OrderCreated::dispatch($order);

        Notification::assertSentTo($user, OrderCreatedNotification::class, function ($n) use ($order) {
            return $n->order->is($order);
        });
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.order_created' => false]);

        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        OrderCreated::dispatch($order);

        Notification::assertNothingSent();
    }

    public function test_no_email_when_order_has_no_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        // Сбросим user-ссылку, чтобы листенер сделал early return
        $order->setRelation('user', null);
        $order->user_id = null;

        OrderCreated::dispatch($order);

        Notification::assertNothingSent();
    }

    public function test_notification_subject_uses_erp_number_when_available(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'number' => 'ORD-2026-0099',
            'erp_number' => 'ZK-12345',
        ]);

        $message = (new OrderCreatedNotification($order))->toMail($user);

        $this->assertSame('Заказ ZK-12345 принят — Pecado.ru', $message->subject);
        $this->assertSame('mail.orders.created', $message->markdown);
        $this->assertSame('ZK-12345', $message->viewData['orderNumber']);
    }

    public function test_notification_subject_falls_back_to_internal_number(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'number' => 'ORD-2026-0007',
            'erp_number' => null,
        ]);

        $message = (new OrderCreatedNotification($order))->toMail($user);

        $this->assertSame('Заказ ORD-2026-0007 принят — Pecado.ru', $message->subject);
    }

    public function test_notification_renders_status_label_and_total(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING_APPROVAL,
            'total_amount' => 1234.56,
            'currency_code' => 'RUB',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Шёлковый шарф',
            'quantity' => 3,
            'price' => 100.00,
            'subtotal' => 300.00,
        ]);

        $rendered = (new OrderCreatedNotification($order))->toMail($user)->render();

        $this->assertStringContainsString('Здравствуйте, Иван', $rendered);
        $this->assertStringContainsString('Ожидается согласование', $rendered);
        $this->assertStringContainsString('Шёлковый шарф', $rendered);
        $this->assertStringContainsString('1 234,56 RUB', $rendered);
        $this->assertStringContainsString('Открыть заказ в кабинете', $rendered);
    }

    public function test_notification_is_queueable(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new OrderCreatedNotification($order),
        );
    }
}
