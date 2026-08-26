<?php

namespace Tests\Feature\User;

use App\Models\EntitySubscription;
use App\Models\NotificationPreference;
use App\Models\NotificationSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Клиент настраивает себе почту сам.
 *
 * Главный критерий всего эпика: если для этого нужны объяснения, решение
 * неверное. Два предыдущих подхода были маршрутизаторами, которые нельзя
 * показать никому, кроме разработчика.
 */
class CabinetNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['email' => 'client@example.com']);
    }

    #[Test]
    public function клиент_видит_только_свои_типы(): void
    {
        $response = $this->actingAs($this->client)
            ->getJson(route('cabinet.notifications.data'))
            ->assertOk();

        $keys = array_column($response->json('rows'), 'key');

        $this->assertContains('orders.status_changed', $keys);
        // Внутреннее уведомление клиенту не показывается.
        $this->assertNotContains('system.question_received', $keys);
    }

    #[Test]
    public function правка_клиента_помечается(): void
    {
        $this->actingAs($this->client)
            ->patchJson(route('cabinet.notifications.update'), [
                'occasion_key' => 'orders.created',
                'is_enabled' => false,
                'destinations' => [],
            ])
            ->assertOk();

        $this->assertTrue(
            NotificationPreference::query()->firstOrFail()->changed_by_client,
        );
    }

    #[Test]
    public function клиент_не_может_адресовать_письмо_роли(): void
    {
        // Роли и конкретные люди — инструмент менеджера: через них клиент
        // мог бы нащупать, кто ещё заведён в справочнике.
        $this->actingAs($this->client)
            ->patchJson(route('cabinet.notifications.update'), [
                'occasion_key' => 'orders.created',
                'is_enabled' => true,
                'destinations' => [['type' => 'contact_role', 'role' => 'accountant']],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function невидимый_клиенту_тип_настроить_нельзя(): void
    {
        $this->actingAs($this->client)
            ->patchJson(route('cabinet.notifications.update'), [
                'occasion_key' => 'system.question_received',
                'is_enabled' => false,
                'destinations' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.occasion_key.0', 'Такое уведомление настроить нельзя.');
    }

    #[Test]
    public function гость_в_настройки_не_попадает(): void
    {
        $this->getJson(route('cabinet.notifications.data'))->assertUnauthorized();
    }

    #[Test]
    public function отписка_по_ссылке_гасит_один_раздел_а_не_всю_почту(): void
    {
        // Клиент, отказавшийся от статусов заказов, не отказывается от актов
        // сверки. Раньше здесь стоял SCOPE_ALL и глушил всё разом.
        $subscription = EntitySubscription::query()->create([
            'user_id' => $this->client->id,
            'section' => 'orders',
            'channel' => 'email',
            'destination' => 'client@example.com',
            'is_active' => true,
        ]);

        $this->get(route('subscriptions.unsubscribe', $subscription->unsubscribe_token))
            ->assertOk();

        $this->assertFalse($subscription->refresh()->is_active);

        $this->assertTrue(NotificationSuppression::blocks('client@example.com', 'orders.status_changed'));
        $this->assertFalse(NotificationSuppression::blocks('client@example.com', 'documents.published'));
    }
}
