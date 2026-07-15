<?php

namespace Tests\Feature\Subscriptions;

use App\Events\EntityChanged;
use App\Listeners\SendEntitySubscriptionNotifications;
use App\Models\EntitySubscription;
use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\User;
use App\Notifications\EntityChangedNotification;
use App\Services\Subscriptions\SubscriptionRegistry;
use App\Subscriptions\EntityChangeNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Доставка уведомлений подписчикам: триггер (создание OrderChangeLog →
 * событие EntityChanged) и листенер-диспетчер (гейт по флагу/разделу,
 * активность подписки, on-demand-маршрутизация на email).
 */
class EntitySubscriptionDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_order_change_log_dispatches_entity_changed_for_owner(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'number' => 'ORD-TEST-1']);

        // Ловим событие реальным слушателем — не Event::fake, чтобы модельный
        // хук created отработал штатно (реальный queued-листенер тоже сработает,
        // но безвреден: afterCommit откладывает его под транзакцией теста).
        $captured = [];
        Event::listen(EntityChanged::class, function (EntityChanged $e) use (&$captured) {
            $captured[] = $e;
        });

        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => 'Добавлен: «Товар» (кол-во: 2)',
            'changes' => ['added' => [], 'removed' => [], 'modified' => []],
            'source' => 'erp',
        ]);

        $this->assertCount(1, $captured);
        $notice = $captured[0]->notice;
        $this->assertSame('orders', $notice->section);
        $this->assertSame($user->id, $notice->ownerUserId);
        $this->assertStringContainsString('ORD-TEST-1', $notice->title);
        $this->assertStringContainsString('Добавлен', $notice->body);
    }

    #[Test]
    public function no_event_when_order_has_no_owner(): void
    {
        $order = Order::factory()->create(['user_id' => null]);

        $captured = [];
        Event::listen(EntityChanged::class, function (EntityChanged $e) use (&$captured) {
            $captured[] = $e;
        });

        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => 'x',
            'changes' => [],
            'source' => 'erp',
        ]);

        $this->assertCount(0, $captured);
    }

    #[Test]
    public function listener_sends_to_active_email_subscriber_when_feature_enabled(): void
    {
        Notification::fake();
        config()->set('notifications.mail.features.entity_subscriptions', true);

        $user = User::factory()->create();
        EntitySubscription::create([
            'user_id' => $user->id, 'section' => 'orders', 'channel' => 'email',
            'destination' => 'buh@example.ru', 'is_active' => true,
        ]);

        $this->dispatchNotice($user->id);

        Notification::assertSentOnDemand(
            EntityChangedNotification::class,
            fn ($notification, $channels, AnonymousNotifiable $notifiable) => ($notifiable->routes['mail'] ?? null) === 'buh@example.ru'
        );
    }

    #[Test]
    public function listener_does_not_send_when_feature_disabled(): void
    {
        Notification::fake();
        config()->set('notifications.mail.features.entity_subscriptions', false);

        $user = User::factory()->create();
        EntitySubscription::create([
            'user_id' => $user->id, 'section' => 'orders', 'channel' => 'email',
            'destination' => 'buh@example.ru', 'is_active' => true,
        ]);

        $this->dispatchNotice($user->id);

        Notification::assertNothingSent();
    }

    #[Test]
    public function listener_skips_inactive_subscription(): void
    {
        Notification::fake();
        config()->set('notifications.mail.features.entity_subscriptions', true);

        $user = User::factory()->create();
        EntitySubscription::create([
            'user_id' => $user->id, 'section' => 'orders', 'channel' => 'email',
            'destination' => 'off@example.ru', 'is_active' => false,
        ]);

        $this->dispatchNotice($user->id);

        Notification::assertNothingSent();
    }

    #[Test]
    public function listener_does_nothing_without_subscriptions(): void
    {
        Notification::fake();
        config()->set('notifications.mail.features.entity_subscriptions', true);

        $user = User::factory()->create();
        $this->dispatchNotice($user->id);

        Notification::assertNothingSent();
    }

    /**
     * Прямой вызов листенера с готовым notice — детерминированно, минуя
     * очередь и afterCommit.
     */
    private function dispatchNotice(int $ownerUserId): void
    {
        $listener = new SendEntitySubscriptionNotifications(new SubscriptionRegistry);
        $listener->handle(new EntityChanged(new EntityChangeNotice(
            section: 'orders',
            ownerUserId: $ownerUserId,
            title: 'Изменение по заказу ORD-1 — Pecado.ru',
            body: 'Добавлен товар',
            url: 'https://pecado.ru/cabinet/orders/1',
            entityLabel: 'Заказ ORD-1',
        )));
    }
}
