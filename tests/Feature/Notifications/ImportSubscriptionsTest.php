<?php

namespace Tests\Feature\Notifications;

use App\Models\ClientContact;
use App\Models\EntitySubscription;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\NotificationSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Перенос подписок кабинета в правила пульта.
 *
 * Главное, что здесь проверяется, — ссылки отписки из уже разосланных писем.
 * Если токен потеряется, клиенты получат 404 вместо отписки, а это прямой
 * путь к жалобе на спам.
 */
class ImportSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partner = User::factory()->create();
    }

    private function subscription(array $overrides = []): EntitySubscription
    {
        return EntitySubscription::create(array_merge([
            'user_id' => $this->partner->id,
            'section' => 'orders',
            'channel' => 'email',
            'destination' => 'buh@romashka.ru',
            'is_active' => true,
        ], $overrides));
    }

    #[Test]
    #[TestDox('Подписка «все типы» переносится одним правилом с маской раздела')]
    public function all_events_becomes_single_masked_rule(): void
    {
        $this->subscription(['events' => null]);

        $this->artisan('notifications:import-subscriptions')->assertSuccessful();

        $rule = NotificationRule::sole();

        // Маска даёт то же, что означало пустое поле: «все типы, включая будущие»
        $this->assertSame('orders.*', $rule->event_key);
        $this->assertSame($this->partner->id, $rule->scope_user_id);
        $this->assertSame('buh@romashka.ru', $rule->recipients->first()->value);
    }

    #[Test]
    #[TestDox('Подписка на два типа даёт два правила')]
    public function selected_events_become_separate_rules(): void
    {
        $this->subscription(['events' => ['items_updated', 'api_shortfall']]);

        $this->artisan('notifications:import-subscriptions')->assertSuccessful();

        $this->assertSame(2, NotificationRule::count());
        $this->assertEqualsCanonicalizing(
            ['orders.items_updated', 'orders.shortfall'],
            NotificationRule::pluck('event_key')->all(),
        );
    }

    #[Test]
    #[TestDox('Выключенная подписка переносится выключенной')]
    public function inactive_subscription_stays_inactive(): void
    {
        $this->subscription(['is_active' => false]);

        $this->artisan('notifications:import-subscriptions')->assertSuccessful();

        $this->assertFalse(NotificationRule::sole()->is_active);
    }

    #[Test]
    #[TestDox('Повторный запуск не создаёт дублей')]
    public function import_is_idempotent(): void
    {
        $this->subscription();

        $this->artisan('notifications:import-subscriptions')->assertSuccessful();
        $this->artisan('notifications:import-subscriptions')->assertSuccessful();

        $this->assertSame(1, NotificationRule::count());
    }

    #[Test]
    #[TestDox('Токен отписки переносится один в один')]
    public function unsubscribe_token_is_preserved(): void
    {
        $subscription = $this->subscription();
        $token = $subscription->unsubscribe_token;

        $this->artisan('notifications:import-subscriptions')->assertSuccessful();

        $this->assertSame($token, NotificationRule::sole()->recipients->first()->unsubscribe_token);
    }

    #[Test]
    #[TestDox('Старая ссылка отписки работает после переноса')]
    public function old_link_still_unsubscribes(): void
    {
        $subscription = $this->subscription();
        $token = $subscription->unsubscribe_token;

        $this->artisan('notifications:import-subscriptions')->assertSuccessful();

        $this->get("/subscriptions/unsubscribe/{$token}")
            ->assertOk()
            ->assertSee('buh@romashka.ru');

        // Адресат вычеркнут из правила и попал в стоп-лист
        $this->assertSame(0, NotificationRuleRecipient::count());
        $this->assertTrue(
            NotificationSuppression::where('email', 'buh@romashka.ru')->exists(),
            'адрес должен попасть в стоп-лист, иначе правило заведут заново и письма пойдут снова',
        );
    }

    #[Test]
    #[TestDox('Ссылка отписки контакта отключает его от всех уведомлений')]
    public function contact_link_unsubscribes_globally(): void
    {
        $contact = ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'email' => 'dir@romashka.ru',
        ]);

        $this->get("/subscriptions/unsubscribe/{$contact->unsubscribe_token}")
            ->assertOk()
            ->assertSee('dir@romashka.ru');

        $this->assertNotNull($contact->fresh()->unsubscribed_at);
        $this->assertTrue(
            NotificationSuppression::where('email', 'dir@romashka.ru')
                ->where('scope', NotificationSuppression::SCOPE_ALL)
                ->exists(),
        );
    }

    #[Test]
    #[TestDox('Неизвестный токен не роняет страницу')]
    public function unknown_token_renders_page(): void
    {
        $this->get('/subscriptions/unsubscribe/'.str_repeat('x', 64))->assertOk();
    }

    #[Test]
    #[TestDox('Пробный прогон ничего не создаёт')]
    public function dry_run_creates_nothing(): void
    {
        $this->subscription();

        $this->artisan('notifications:import-subscriptions', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, NotificationRule::count());
    }
}
