<?php

namespace Tests\Feature\User;

use App\Models\NotificationPreference;
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
    public function клиент_не_видит_нашу_внутреннюю_маршрутизацию(): void
    {
        // Финансовые уведомления по умолчанию адресованы персональному
        // менеджеру. В кабинете партнёра такая строка обязана выглядеть
        // выключенной: письма он не получает, а кому оно уходит внутри —
        // не его дело.
        $response = $this->actingAs($this->client)
            ->getJson(route('cabinet.notifications.data'))
            ->assertOk();

        $row = collect($response->json('rows'))->firstWhere('key', 'finance.overdue_started');

        $this->assertFalse($row['enabled']);
        $this->assertSame([], $row['destinations']);
    }

    #[Test]
    public function клиент_видит_включённым_то_что_адресовано_ему(): void
    {
        \App\Models\NotificationPreference::query()->create([
            'user_id' => $this->client->id,
            'occasion_key' => 'documents.published',
            'is_enabled' => true,
            'destinations' => [['type' => 'login']],
        ]);

        $response = $this->actingAs($this->client)
            ->getJson(route('cabinet.notifications.data'))
            ->assertOk();

        $row = collect($response->json('rows'))->firstWhere('key', 'documents.published');

        $this->assertTrue($row['enabled']);
        $this->assertSame('login', $row['destinations'][0]['type']);
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
}
