<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Защита от двойного письма на время переезда на подписки.
 *
 * Пока по поводу пишет зашитый листенер, правило-фильтр молчит. Порядок шагов
 * при переезде можно перепутать, а письмо клиенту отозвать нельзя — поэтому
 * дубль запрещён кодом, а не инструкцией в карточке задачи.
 */
class MailLegacyConflictTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create([
            'user_id' => $this->manager->id,
            'email' => 'manager@pecado.ru',
        ]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config([
            'mail_stream.enabled' => true,
            'mail_stream.autosend' => true,
            'notifications.mail.features.crm_outbound' => true,
        ]);

        CrmMailRule::query()->create([
            'name' => 'Смена статуса — клиенту',
            'conditions' => ['all' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'заказ']]],
            'recipients' => [CrmMailRule::RECIPIENT_CLIENT],
            'auto_send' => true,
            'is_active' => true,
        ]);
    }

    private function statusLetter(): CrmEmail
    {
        return app(MailStream::class)->capture(new Occasion(
            key: 'orders.status_changed',
            clientUserId: $this->client->id,
            data: [
                'order_id' => 1,
                'order_number' => '1001',
                'status' => 'shipping',
                'status_label' => 'В процессе отгрузки',
            ],
        ));
    }

    #[Test]
    public function при_включённом_старом_флаге_правило_молчит(): void
    {
        Mail::fake();
        config(['notifications.mail.features.order_status_changes' => true]);

        $letter = $this->statusLetter()->refresh();

        $this->assertNotSame(EmailStatus::SENT, $letter->status);
        $this->assertStringContainsString('старый механизм', (string) $letter->skip_reason);
        $this->assertStringContainsString('MAIL_FEATURE_ORDER_STATUS', (string) $letter->skip_reason);
        Mail::assertNothingSent();
    }

    #[Test]
    public function после_выключения_флага_правило_отправляет(): void
    {
        Mail::fake();
        config(['notifications.mail.features.order_status_changes' => false]);

        $letter = $this->statusLetter()->refresh();

        $this->assertNull($letter->skip_reason);
        $this->assertSame(EmailStatus::SENT, $letter->status);
    }

    #[Test]
    public function чужая_аудитория_дублем_не_считается(): void
    {
        Mail::fake();
        config(['notifications.mail.features.order_status_changes' => true]);

        // Старый листенер пишет клиенту. Правило, целящееся в менеджера,
        // дубля не создаёт — и молчать не должно.
        CrmMailRule::query()->update(['recipients' => [CrmMailRule::RECIPIENT_MANAGER]]);

        $letter = $this->statusLetter()->refresh();

        $this->assertSame(['manager@pecado.ru'], (array) $letter->to);
        $this->assertNull($letter->skip_reason);
        $this->assertSame(EmailStatus::SENT, $letter->status);
    }

    #[Test]
    public function повод_без_старого_отправителя_не_блокируется(): void
    {
        Mail::fake();
        config(['notifications.mail.features.order_status_changes' => true]);

        $letter = app(MailStream::class)->capture(new Occasion(
            key: 'orders.attributes_updated',
            clientUserId: $this->client->id,
            data: ['order_id' => 2, 'order_number' => '1002', 'changes' => []],
        ));

        $this->assertNull($letter->refresh()->skip_reason);
        $this->assertSame(EmailStatus::SENT, $letter->refresh()->status);
    }
}
