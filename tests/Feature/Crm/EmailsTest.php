<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\EmailStatus;
use App\Jobs\SendCrmEmailJob;
use App\Mail\CrmManagerMail;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailDeliveryLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

class EmailsTest extends TestCase
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
        $managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $managerProfile->id,
            'email' => 'client@example.com',
        ]);

        config(['notifications.mail.features.crm_outbound' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'to' => ['client@example.com'],
            'subject' => 'Коммерческое предложение',
            'body_html' => '<p>Здравствуйте!</p>',
            'entity_type' => 'client',
            'entity_id' => $this->client->id,
        ], $overrides);
    }

    #[Test]
    public function manager_creates_draft_for_own_client(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('crm.emails.store'), $this->payload());

        $response->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('status_label', 'Черновик')
            // Ответ клиента должен прийти живому человеку, а не в общий ящик отдела.
            ->assertJsonPath('reply_to', 'manager@pecado.ru');

        $email = CrmEmail::query()->firstOrFail();

        $this->assertSame($this->manager->id, $email->user_id);
        $this->assertSame($this->client->id, $email->client_user_id);
    }

    #[Test]
    public function draft_is_sent_and_journal_records_the_result(): void
    {
        Mail::fake();

        $email = CrmEmail::factory()->by($this->manager)->on($this->client)->create();

        // Очередь в тестах синхронна: к моменту ответа задание уже отработало,
        // поэтому в журнале сразу «отправлено», а не «в очереди».
        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.send', $email))
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        Mail::assertSent(CrmManagerMail::class, fn (CrmManagerMail $mail) => $mail->email->is($email));

        $email->refresh();
        $this->assertSame(EmailStatus::SENT, $email->status);
        $this->assertNotNull($email->sent_at);
    }

    #[Test]
    public function sending_is_blocked_while_feature_flag_is_off(): void
    {
        Bus::fake();
        config(['notifications.mail.features.crm_outbound' => false]);

        $email = CrmEmail::factory()->by($this->manager)->on($this->client)->create();

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.send', $email))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Отправка писем из CRM выключена администратором.');

        Bus::assertNotDispatched(SendCrmEmailJob::class);
        $this->assertSame(EmailStatus::DRAFT, $email->refresh()->status);
    }

    #[Test]
    public function draft_is_created_even_when_sending_is_off(): void
    {
        config(['notifications.mail.features.crm_outbound' => false]);

        // Составить письмо можно всегда — флаг гейтит отправку, а не работу.
        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'draft');
    }

    #[Test]
    public function failed_delivery_is_recorded_and_does_not_vanish(): void
    {
        $email = CrmEmail::factory()->by($this->manager)->on($this->client)->create();

        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP недоступен'));

        try {
            (new SendCrmEmailJob($email))->handle(app(MailDeliveryLedger::class));
        } catch (\Throwable $exception) {
            (new SendCrmEmailJob($email))->failed($exception);
        }

        $email->refresh();

        $this->assertSame(EmailStatus::FAILED, $email->status);
        $this->assertStringContainsString('SMTP недоступен', (string) $email->error);
    }

    #[Test]
    public function editing_after_failure_returns_email_to_drafts(): void
    {
        $email = CrmEmail::factory()->by($this->manager)->on($this->client)->create([
            'status' => EmailStatus::FAILED,
            'error' => 'SMTP недоступен',
        ]);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.emails.update', $email), ['subject' => 'Исправленная тема'])
            ->assertOk()
            ->assertJsonPath('status', 'draft');

        $this->assertNull($email->refresh()->error);
    }

    #[Test]
    public function sent_email_cannot_be_edited_or_deleted(): void
    {
        $email = CrmEmail::factory()->by($this->manager)->on($this->client)->sent()->create();

        $this->actingAs($this->manager)
            ->patchJson(route('crm.emails.update', $email), ['subject' => 'Правка задним числом'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.emails.destroy', $email))
            ->assertForbidden();
    }

    #[Test]
    public function email_to_foreign_client_is_not_created(): void
    {
        $foreignManager = PersonalManager::factory()->create();
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignManager->id]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.store'), $this->payload([
                'entity_id' => $foreignClient->id,
            ]))
            ->assertNotFound();

        $this->assertSame(0, CrmEmail::query()->count());
    }

    #[Test]
    public function foreign_email_is_invisible(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        $foreignManager = PersonalManager::factory()->create(['user_id' => $stranger->id]);
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignManager->id]);

        $email = CrmEmail::factory()->by($stranger)->on($foreignClient)->create();

        $this->actingAs($this->manager)->getJson(route('crm.emails.show', $email))->assertForbidden();

        $ids = array_column($this->journalFor($this->manager), 'id');
        $this->assertNotContains($email->id, $ids);
    }

    #[Test]
    public function journal_shows_letters_to_own_clients_written_by_colleagues(): void
    {
        // Переписка с клиентом — история клиента, а не личное дело автора:
        // письмо РОПа по моему клиенту я вижу.
        $head = User::factory()->create();
        $head->assignRole('sales-head');
        $email = CrmEmail::factory()->by($head)->on($this->client)->sent()->create();

        // Письмо отправлено, поэтому смотрим в папку «Отправленные»:
        // по умолчанию открываются «Черновики» — рабочая папка.
        $ids = array_column($this->journalFor($this->manager, 'sent'), 'id');

        $this->assertContains($email->id, $ids);
    }

    #[Test]
    public function sent_email_appears_in_client_timeline_and_draft_does_not(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        CrmEmail::factory()->by($this->manager)->on($order)->sent()->create(['subject' => 'Отправленное']);
        CrmEmail::factory()->by($this->manager)->on($order)->create(['subject' => 'Черновик']);

        // Сам заказ тоже событие ленты, поэтому сужаемся до писем.
        $timeline = $this->actingAs($this->manager)
            ->getJson(route('crm.clients.timeline', [$this->client, 'types' => ['email']]))
            ->assertOk()
            ->json('data');

        // Письмо становится событием в жизни клиента только когда его отправили.
        $this->assertCount(1, $timeline);
        $this->assertSame('email', $timeline[0]['type']);
        $this->assertSame('Отправленное', $timeline[0]['title']);
        $this->assertSame('order', $timeline[0]['entity']['type']);
    }

    #[Test]
    public function template_placeholders_are_expanded(): void
    {
        $template = CrmEmailTemplate::factory()->create();

        $this->actingAs($this->manager)
            ->getJson(route('crm.emails.template', [
                'template' => $template->id,
                'client_id' => $this->client->id,
            ]))
            ->assertOk()
            ->assertJsonPath('subject', "Предложение для {$this->client->name}")
            ->assertJsonFragment(['body_html' => "<p>Здравствуйте, {$this->client->name}!</p><p>С уважением, {$this->manager->name}</p>"]);
    }

    #[Test]
    public function validation_errors_are_in_russian(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.store'), $this->payload(['to' => []]))
            ->assertStatus(422)
            ->assertJsonPath('errors.to.0', 'Укажите хотя бы одного получателя.');

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.store'), $this->payload(['to' => ['не-адрес']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to.0' => 'Адрес получателя указан неверно.']);
    }

    #[Test]
    public function without_permission_journal_is_closed(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('sales-manager');
        \Spatie\Permission\Models\Role::findByName('sales-manager')->revokePermissionTo('crm-emails.view');

        $this->actingAs($outsider)->get(route('crm.emails.index'))->assertForbidden();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journalFor(User $actor, string $folder = 'drafts'): array
    {
        $response = $this->actingAs($actor)->get(route('crm.emails.index', ['folder' => $folder]));

        return $response->viewData('page')['props']['emails']['data'];
    }
}
