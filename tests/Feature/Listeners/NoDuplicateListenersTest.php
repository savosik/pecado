<?php

namespace Tests\Feature\Listeners;

use App\Events\OrdersPlaced;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\User;
use App\Notifications\Orders\NewOrderForManagerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Листенер не должен быть зарегистрирован дважды.
 *
 * Laravel 11+ сам находит листенеры в `app/Listeners` по типу аргумента `handle()`,
 * а проект регистрирует их явно в `AppServiceProvider::boot()`. Пока автообнаружение
 * было включено, каждый такой листенер срабатывал дважды — и клиент получал каждое
 * письмо о заказе в двух экземплярах. Автообнаружение отключено в `bootstrap/app.php`.
 *
 * Тест страхует именно от возврата дубля: он падает, если кто-то вернёт
 * автообнаружение или зарегистрирует листенер вторым способом.
 */
class NoDuplicateListenersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ни_одно_событие_не_имеет_дублирующихся_листенеров(): void
    {
        $events = [
            \App\Events\OrderCreated::class,
            \App\Events\OrdersPlaced::class,
            \App\Events\OrderUpdated::class,
            \App\Events\OrderDeleted::class,
            \App\Events\ReturnCreated::class,
            \App\Events\ReturnStatusChanged::class,
            \App\Events\UserRegisteredOnSite::class,
            \App\Events\UserPasswordChanged::class,
        ];

        foreach ($events as $event) {
            $names = array_map(
                // Имя листенера: у явной регистрации это 'Класс@handle',
                // у автообнаруженной — замыкание, поэтому сравниваем по классу
                static fn ($listener) => is_string($listener) ? explode('@', $listener)[0] : null,
                Event::getListeners($event),
            );

            $names = array_values(array_filter($names));
            $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n) => $n > 1));

            $this->assertSame(
                [],
                $duplicates,
                "Событие {$event} имеет дублирующиеся листенеры: ".implode(', ', $duplicates)
                    .'. Скорее всего, включилось автообнаружение (bootstrap/app.php → withEvents).',
            );
        }
    }

    /**
     * Сквозная проверка: одно событие — одно письмо, а не два.
     */
    #[Test]
    public function одно_событие_даёт_одно_письмо_менеджеру(): void
    {
        Notification::fake();

        $manager = PersonalManager::factory()->create(['email' => 'anna@pecado.ru']);
        $user = User::factory()->create(['personal_manager_id' => $manager->id]);
        $company = Company::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);

        OrdersPlaced::dispatch(collect([$order]));

        Notification::assertSentOnDemandTimes(NewOrderForManagerNotification::class, 1);
    }
}
