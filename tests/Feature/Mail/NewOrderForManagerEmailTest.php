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

    public function test_dispatches_to_all_sales_managers(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'mgr1@pecado.ru'])->assignRole('sales-manager');
        User::factory()->create(['email' => 'mgr2@pecado.ru'])->assignRole('sales-manager');
        User::factory()->create(['email' => 'mgr3@pecado.ru'])->assignRole('sales-manager');

        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewOrderForManagerNotification::class,
            function ($n, $channels, $notifiable) {
                $routes = $notifiable->routes['mail'] ?? [];

                return count($routes) === 3
                    && in_array('mgr1@pecado.ru', $routes, true)
                    && in_array('mgr2@pecado.ru', $routes, true)
                    && in_array('mgr3@pecado.ru', $routes, true);
            }
        );
    }

    public function test_personal_manager_added_to_recipients(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'sales@pecado.ru'])->assignRole('sales-manager');
        $pm = PersonalManager::create(['name' => 'Анна', 'email' => 'anna.personal@pecado.ru']);

        $client = User::factory()->create(['personal_manager_id' => $pm->id]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewOrderForManagerNotification::class,
            function ($n, $channels, $notifiable) {
                $routes = $notifiable->routes['mail'] ?? [];

                return in_array('sales@pecado.ru', $routes, true)
                    && in_array('anna.personal@pecado.ru', $routes, true);
            }
        );
    }

    public function test_no_recipients_means_no_notification(): void
    {
        Notification::fake();
        // Никаких sales-manager-ов, никаких personal_manager-ов

        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertNothingSent();
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.manager_new_order' => false]);

        User::factory()->create(['email' => 'mgr@pecado.ru'])->assignRole('sales-manager');

        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertNothingSent();
    }

    public function test_manager_without_email_is_skipped(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'real@pecado.ru'])->assignRole('sales-manager');
        // Юзер с пустым email
        User::factory()->create(['email' => ''])->assignRole('sales-manager');

        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewOrderForManagerNotification::class,
            function ($n, $channels, $notifiable) {
                $routes = $notifiable->routes['mail'] ?? [];

                return count($routes) === 1 && $routes[0] === 'real@pecado.ru';
            }
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
