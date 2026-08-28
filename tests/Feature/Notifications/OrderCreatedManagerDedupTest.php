<?php

namespace Tests\Feature\Notifications;

use App\Enums\Crm\EmailStatus;
use App\Models\NotificationPreference;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\EnablesClientNotifications;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * О новом заказе менеджеру пишет служебный листенер (`staff.order_created`).
 *
 * Настройка партнёра «оформлен заказ → менеджер» дала бы то же письмо второй
 * раз. С note-10 защиты флагами больше нет, поэтому дубль исключает сам
 * диспетчер: адрес персонального менеджера из уведомления партнёра выкидывается.
 */
class OrderCreatedManagerDedupTest extends TestCase
{
    use EnablesClientNotifications;
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create([
            'user_id' => $manager->id,
            'email' => 'manager@pecado.ru',
        ]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config([
            'mail_stream.enabled' => true,
            'mail_stream.autosend' => true,
            'mail_stream.notifications_live' => true,
            'notifications.mail.features.crm_outbound' => true,
        ]);

        $this->enableNotificationsFor($this->client);
    }

    private function capture(string $key, array $destinations): \App\Models\CrmEmail
    {
        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $this->client->id, 'occasion_key' => $key],
            ['is_enabled' => true, 'destinations' => $destinations],
        );

        return app(MailStream::class)->capture(new Occasion(
            key: $key,
            clientUserId: $this->client->id,
            data: ['order_id' => 1, 'order_number' => '1001', 'status' => 'shipping', 'status_label' => 'В процессе отгрузки', 'total' => 100],
        ))->refresh();
    }

    #[Test]
    public function менеджера_из_уведомления_о_новом_заказе_выкидываем(): void
    {
        Mail::fake();

        $letter = $this->capture('orders.created', [['type' => 'login'], ['type' => 'manager']]);

        $this->assertSame(['client@example.com'], (array) $letter->to);
        $this->assertSame(EmailStatus::SENT, $letter->status);
    }

    #[Test]
    public function если_кроме_менеджера_никого_письмо_не_уходит_с_понятной_причиной(): void
    {
        Mail::fake();

        $letter = $this->capture('orders.created', [['type' => 'manager']]);

        $this->assertSame([], (array) $letter->to);
        $this->assertSame(EmailStatus::UNMATCHED, $letter->status);
        $this->assertStringContainsString('служебное письмо', (string) $letter->skip_reason);
        Mail::assertNothingSent();
    }

    #[Test]
    public function по_другим_типам_менеджер_остаётся_адресатом(): void
    {
        Mail::fake();

        $letter = $this->capture('orders.status_changed', [['type' => 'manager']]);

        $this->assertSame(['manager@pecado.ru'], (array) $letter->to);
        $this->assertSame(EmailStatus::SENT, $letter->status);
    }
}
