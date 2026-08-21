<?php

namespace Tests\Feature\Notifications;

use App\Events\EntityChanged;
use App\Listeners\SendEntitySubscriptionNotifications;
use App\Models\EntitySubscription;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\User;
use App\Notifications\EntityChangedNotification;
use App\Notifications\Pulse\PulseNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\NotificationPulse;
use App\Subscriptions\EntityChangeNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Подписки кабинета и пульт не должны слать два письма об одном изменении.
 *
 * Живой риск: на проде есть активные подписки, а их листенер до этого гейта
 * не имел. При переводе заказов в боевой режим подписчик получил бы два
 * письма — от пульта и от старого механизма.
 */
class SubscriptionDoubleSendTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.domains.orders.enabled' => true,
            'notifications.mail.features.entity_subscriptions' => true,
            'subscriptions.sections.orders.enabled' => true,
        ]);

        Notification::fake();

        $this->partner = User::factory()->create(['email' => 'client@x.ru']);

        EntitySubscription::create([
            'user_id' => $this->partner->id,
            'section' => 'orders',
            'channel' => 'email',
            'destination' => 'buh@x.ru',
            'is_active' => true,
        ]);
    }

    private function fireLegacy(): void
    {
        app(SendEntitySubscriptionNotifications::class)->handle(new EntityChanged(
            new EntityChangeNotice(
                section: 'orders',
                ownerUserId: $this->partner->id,
                title: 'Изменение',
                body: 'Тело',
                event: 'items_updated',
            )
        ));
    }

    private function firePulse(): void
    {
        $rule = NotificationRule::factory()->forUser($this->partner->id)->create([
            'event_key' => 'orders.items_updated',
        ]);
        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'buh@x.ru',
        ]);

        app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.items_updated',
            clientUserId: $this->partner->id,
            data: ['added_count' => 1],
            view: ['title' => 'Изменение'],
        ));
    }

    private function sentCountOf(string $class): int
    {
        $count = 0;

        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                $count += count($byType[$class] ?? []);
            }
        }

        return $count;
    }

    #[Test]
    #[TestDox('В теневом режиме письмо шлёт подписка, пульт молчит')]
    public function shadow_keeps_subscription_sending(): void
    {
        config(['notification_pulse.mode' => 'shadow']);

        $this->firePulse();
        $this->fireLegacy();

        $this->assertSame(1, $this->sentCountOf(EntityChangedNotification::class), 'подписка должна отправить');
        $this->assertSame(0, $this->sentCountOf(PulseNotification::class), 'пульт в тени молчит');
    }

    #[Test]
    #[TestDox('После перевода в боевой режим шлёт пульт, подписка молчит')]
    public function live_silences_subscription(): void
    {
        config([
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => ['orders.items_updated'],
        ]);

        $this->firePulse();
        $this->fireLegacy();

        // Ровно одно письмо об одном изменении
        $this->assertSame(0, $this->sentCountOf(EntityChangedNotification::class), 'подписка обязана молчать');
        $this->assertSame(1, $this->sentCountOf(PulseNotification::class));
    }

    #[Test]
    #[TestDox('Подписка «все типы» гасится маской раздела')]
    public function mask_silences_untyped_subscription(): void
    {
        config([
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => ['orders.*'],
        ]);

        app(SendEntitySubscriptionNotifications::class)->handle(new EntityChanged(
            new EntityChangeNotice(
                section: 'orders',
                ownerUserId: $this->partner->id,
                title: 'Изменение',
                body: 'Тело',
                event: null,
            )
        ));

        $this->assertSame(0, $this->sentCountOf(EntityChangedNotification::class));
    }

    #[Test]
    #[TestDox('Непереведённое событие остаётся за подпиской')]
    public function untouched_event_stays_with_subscription(): void
    {
        config([
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => ['orders.status_changed'],
        ]);

        $this->fireLegacy();

        $this->assertSame(1, $this->sentCountOf(EntityChangedNotification::class));
    }

    #[Test]
    #[TestDox('Импортированная подписка после перевода шлёт ровно одно письмо')]
    public function imported_subscription_sends_once(): void
    {
        // Полный путь перехода: импорт подписок → перевод в live
        $this->artisan('notifications:import-subscriptions')->assertSuccessful();

        config([
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => ['orders.*'],
        ]);

        app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.items_updated',
            clientUserId: $this->partner->id,
            data: ['added_count' => 1],
            view: ['title' => 'Изменение'],
        ));

        $this->fireLegacy();

        $this->assertSame(0, $this->sentCountOf(EntityChangedNotification::class));
        $this->assertSame(1, $this->sentCountOf(PulseNotification::class), 'подписчик не должен потерять письмо');
    }
}
