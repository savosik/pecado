<?php

namespace Tests\Feature\Mail;

use App\Events\OrdersPlaced;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Orders\NewOrderForManagerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewOrderForManagerEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        config(['notifications.mail.features.manager_new_order' => true]);
    }

    public function test_goes_only_to_personal_manager_of_the_client(): void
    {
        Notification::fake();

        $pm = PersonalManager::create(['name' => 'Анна', 'email' => 'anna.personal@pecado.ru']);
        $other = PersonalManager::create(['name' => 'Борис', 'email' => 'boris@pecado.ru']);
        User::factory()->create(['personal_manager_id' => $other->id]);

        $client = User::factory()->create(['personal_manager_id' => $pm->id]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertSentTimes(NewOrderForManagerNotification::class, 1);
        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewOrderForManagerNotification::class,
            fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'anna.personal@pecado.ru'
        );
    }

    /**
     * Роль сотрудника больше не делает его получателем: заказ уходит тому,
     * кто ведёт клиента, а не всем, у кого в системе есть роль продажника.
     */
    public function test_sales_manager_role_alone_does_not_receive(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'mgr1@pecado.ru'])->assignRole('sales-manager');
        User::factory()->create(['email' => 'mgr2@pecado.ru'])->assignRole('sales-manager');

        $pm = PersonalManager::create(['name' => 'Анна', 'email' => 'anna.personal@pecado.ru']);
        $client = User::factory()->create(['personal_manager_id' => $pm->id]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertSentTimes(NewOrderForManagerNotification::class, 1);
        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewOrderForManagerNotification::class,
            fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'anna.personal@pecado.ru'
        );
    }

    public function test_client_without_manager_falls_back_to_configured_recipients(): void
    {
        Notification::fake();
        config(['notifications.mail.order_fallback_recipients' => ['rop@pecado.ru', 'opt@pecado.ru']]);

        $client = User::factory()->create(['personal_manager_id' => null]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        // Каждому отдельным письмом — адреса получателей не видны друг другу
        Notification::assertSentTimes(NewOrderForManagerNotification::class, 2);
        foreach (['rop@pecado.ru', 'opt@pecado.ru'] as $email) {
            Notification::assertSentTo(
                new AnonymousNotifiable,
                NewOrderForManagerNotification::class,
                fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === $email
            );
        }
    }

    public function test_no_manager_and_no_fallback_means_no_notification(): void
    {
        Notification::fake();
        config(['notifications.mail.order_fallback_recipients' => []]);

        $client = User::factory()->create(['personal_manager_id' => null]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertNothingSent();
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.manager_new_order' => false]);

        $pm = PersonalManager::create(['name' => 'Анна', 'email' => 'anna.personal@pecado.ru']);
        $client = User::factory()->create(['personal_manager_id' => $pm->id]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertNothingSent();
    }

    public function test_manager_without_email_falls_back(): void
    {
        Notification::fake();
        config(['notifications.mail.order_fallback_recipients' => ['rop@pecado.ru']]);

        $pm = PersonalManager::create(['name' => 'Без почты', 'email' => null]);
        $client = User::factory()->create(['personal_manager_id' => $pm->id]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewOrderForManagerNotification::class,
            fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'rop@pecado.ru'
        );
    }

    public function test_notification_subject_and_payload(): void
    {
        $client = User::factory()->create(['name' => 'Клиент Петров']);
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'number' => 'ORD-2026-0123',
            'total_amount' => 5000.50,
        ]);

        $message = (new NewOrderForManagerNotification($order))
            ->toMail(new AnonymousNotifiable);

        $this->assertSame('Новый заказ ORD-2026-0123 — Pecado.ru', $message->subject);
        $this->assertSame('mail.orders.manager-new', $message->markdown);
        $this->assertSame('Клиент Петров', $message->viewData['clientName']);
        $this->assertStringContainsString('/admin/orders/', $message->viewData['adminUrl']);
    }

    public function test_notification_renders(): void
    {
        $client = User::factory()->create(['name' => 'Клиент Петров']);
        $order = Order::factory()->create([
            'user_id' => $client->id,
            'number' => 'ORD-2026-0123',
            'total_amount' => 5000.50,
            'currency_code' => 'RUB',
        ]);

        $rendered = (new NewOrderForManagerNotification($order))
            ->toMail(new AnonymousNotifiable)
            ->render();

        $this->assertStringContainsString('Новый заказ ORD-2026-0123', $rendered);
        $this->assertStringContainsString('Клиент Петров', $rendered);
        $this->assertStringContainsString('5 000,50 RUB', $rendered);
        $this->assertStringContainsString('Открыть заказ в админке', $rendered);
    }

    public function test_notification_is_queueable(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id]);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new NewOrderForManagerNotification($order),
        );
    }
}
