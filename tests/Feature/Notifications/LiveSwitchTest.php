<?php

namespace Tests\Feature\Notifications;

use App\Enums\OrderStatus;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Orders\OrderStatusChangedNotification;
use App\Notifications\Pulse\PulseNotification;
use App\Services\Notifications\Pulse\PulseMode;
use App\Services\Notifications\Pulse\SystemRulesSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Переключение события со старого листенера на пульт.
 *
 * Главная проверка перехода: письмо об одном событии должно уйти ровно один
 * раз — либо старым механизмом, либо новым, но не обоими сразу. Один флаг
 * управляет обеими сторонами, поэтому дубль невозможен по конструкции;
 * этот тест фиксирует, что так и есть.
 */
class LiveSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.domains.orders.enabled' => true,
            'notifications.mail.features.order_status_changes' => true,
            'notifications.mail.order_statuses_to_notify_client' => ['closed'],
        ]);

        $manager = PersonalManager::factory()->create(['email' => 'manager@pecado.ru']);
        $this->client = User::factory()->create([
            'email' => 'client@x.ru',
            'personal_manager_id' => $manager->id,
        ]);

        app(SystemRulesSynchronizer::class)->sync();
    }

    private function changeStatus(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->client->id,
            'status' => OrderStatus::SHIPPING,
        ]);

        // OrderUpdated модель диспатчит сама ($dispatchesEvents), поэтому
        // транзиентные признаки выставляются до сохранения, а событие вручную
        // не поднимается — иначе получился бы дубль, которого в проде нет.
        $order->fromErp = true;
        $order->previousStatus = OrderStatus::SHIPPING;
        $order->status = OrderStatus::CLOSED;
        $order->save();
    }

    #[Test]
    #[TestDox('В теневом режиме письмо шлёт старый листенер, пульт молчит')]
    public function shadow_keeps_legacy_sending(): void
    {
        config(['notification_pulse.mode' => 'shadow']);
        Notification::fake();

        $this->changeStatus();

        Notification::assertSentTo($this->client, OrderStatusChangedNotification::class);
        Notification::assertNotSentTo(new \Illuminate\Notifications\AnonymousNotifiable, PulseNotification::class);
    }

    #[Test]
    #[TestDox('После перевода в боевой режим шлёт пульт, старый листенер молчит')]
    public function live_switches_sender(): void
    {
        config([
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => ['orders.status_changed'],
        ]);

        // Системное правило заведено выключенным по флагу; включаем его,
        // как это сделал бы РОП в пульте перед переходом.
        NotificationRule::where('system_key', 'sys.orders.status_changed.client')
            ->update(['is_active' => true]);

        Notification::fake();

        $this->changeStatus();

        // Старый листенер промолчал
        Notification::assertNotSentTo($this->client, OrderStatusChangedNotification::class);

        // Письмо ушло ровно одно — от пульта, на тот же адрес
        $addresses = [];
        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                foreach ($byType[PulseNotification::class] ?? [] as $item) {
                    $addresses[] = $item['notifiable']->routes['mail'];
                }
            }
        }

        $this->assertSame(['client@x.ru'], $addresses);
    }

    #[Test]
    #[TestDox('Событие не в списке переведённых остаётся за старым листенером')]
    public function untouched_event_stays_with_legacy(): void
    {
        config([
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => ['orders.shipped'],
        ]);

        Notification::fake();

        $this->changeStatus();

        Notification::assertSentTo($this->client, OrderStatusChangedNotification::class);
    }

    #[Test]
    #[TestDox('Флаг PulseMode един для обеих сторон')]
    public function pulse_mode_is_single_source_of_truth(): void
    {
        config(['notification_pulse.mode' => 'shadow']);
        $this->assertFalse(PulseMode::handles('orders.status_changed'));
        $this->assertTrue(PulseMode::accepts('orders.status_changed'), 'в тени сигнал принимается для сверки');

        config(['notification_pulse.mode' => 'live', 'notification_pulse.live_events' => ['orders.status_changed']]);
        $this->assertTrue(PulseMode::handles('orders.status_changed'));
        $this->assertFalse(PulseMode::handles('orders.created'), 'непереведённое событие остаётся за старым кодом');

        config(['notification_pulse.enabled' => false]);
        $this->assertFalse(PulseMode::handles('orders.status_changed'));
        $this->assertFalse(PulseMode::accepts('orders.status_changed'), 'выключенный пульт не принимает сигналов');
    }

    #[Test]
    #[TestDox('Системные правила идемпотентны и не перетирают ручные правки')]
    public function system_rules_sync_preserves_manual_changes(): void
    {
        $rule = NotificationRule::where('system_key', 'sys.orders.status_changed.client')->sole();

        // РОП выключил правило и поднял приоритет
        $rule->update(['is_active' => false, 'priority' => 42]);
        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'extra@pecado.ru',
        ]);

        app(SystemRulesSynchronizer::class)->sync();

        $rule->refresh();
        $this->assertFalse($rule->is_active, 'повторная синхронизация не должна включать выключенное');
        $this->assertSame(42, $rule->priority);
        $this->assertCount(2, $rule->recipients, 'добавленный вручную получатель не должен исчезнуть');

        // Правил не стало больше
        $this->assertSame(1, NotificationRule::where('system_key', 'sys.orders.status_changed.client')->count());
    }

    #[Test]
    #[TestDox('Системное правило создаётся выключенным, если письмо выключено флагом')]
    public function system_rule_respects_current_feature_flag(): void
    {
        NotificationRule::query()->forceDelete();

        config(['notifications.mail.features.order_created' => false]);
        app(SystemRulesSynchronizer::class)->sync();

        $rule = NotificationRule::where('system_key', 'sys.orders.created.client')->sole();

        // Ни одно письмо не должно «включиться само» от появления пульта
        $this->assertFalse($rule->is_active);
    }

    #[Test]
    #[TestDox('Системное правило нельзя удалить, только выключить')]
    public function system_rule_is_protected(): void
    {
        $rule = NotificationRule::where('system_key', 'sys.orders.created.manager')->sole();

        $this->assertTrue($rule->is_system);

        $manager = User::factory()->create();
        $this->assertFalse($rule->isEditableBy($manager), 'без права на все правила системное не редактируется');
    }

    #[Test]
    #[TestDox('Системное правило менеджера повторяет прежнюю маршрутизацию')]
    public function manager_rule_mirrors_legacy_routing(): void
    {
        $rule = NotificationRule::where('system_key', 'sys.orders.created.manager')->sole();

        $kinds = $rule->recipients->pluck('kind')->all();

        $this->assertContains(NotificationRuleRecipient::KIND_PERSONAL_MANAGER, $kinds);

        // Резервный адрес именно резервный: он вступает, только если менеджера нет
        $fallback = $rule->recipients->firstWhere('kind', NotificationRuleRecipient::KIND_CONFIG_LIST);
        $this->assertNotNull($fallback);
        $this->assertTrue($fallback->is_fallback);
        $this->assertSame('notifications.mail.order_fallback_recipients', $fallback->value);
    }
}
