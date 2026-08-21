<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationCampaign;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Рассылки в интерфейсе CRM.
 *
 * Ключевое разграничение: собрать черновик может менеджер, а запустить
 * рассылку — только руководитель. Письмо по всей базе не должно уходить
 * по решению одного человека из отдела.
 */
class CampaignsCrmTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.domains.campaigns.enabled' => true,
        ]);

        Notification::fake();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
    }

    private function payload(): array
    {
        return [
            'name' => 'Акция сентября',
            'subject' => 'Скидки',
            'body_html' => '<p>Текст</p>',
            'segment' => ['roles' => [ClientContactRole::BUYER->value]],
        ];
    }

    private function campaignWithAudience(): NotificationCampaign
    {
        $partner = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $partner->id]);

        ClientContact::factory()->role(ClientContactRole::BUYER)->create([
            'user_id' => $partner->id,
            'company_id' => $company->id,
            'email' => 'buyer@x.ru',
            'marketing_consent' => true,
        ]);

        $campaign = NotificationCampaign::create($this->payload() + [
            'status' => NotificationCampaign::STATUS_DRAFT,
        ]);

        app(\App\Services\Notifications\Pulse\CampaignSender::class)->buildAudience($campaign);

        return $campaign->fresh();
    }

    #[Test]
    #[TestDox('Менеджер создаёт черновик рассылки')]
    public function manager_creates_draft(): void
    {
        $this->actingAs($this->manager)
            ->post('/crm/notifications/campaigns', $this->payload())
            ->assertRedirect();

        $this->assertSame(NotificationCampaign::STATUS_DRAFT, NotificationCampaign::sole()->status);
    }

    #[Test]
    #[TestDox('Менеджер собирает аудиторию и видит разбивку')]
    public function manager_builds_audience(): void
    {
        $partner = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $partner->id]);

        ClientContact::factory()->role(ClientContactRole::BUYER)->create([
            'user_id' => $partner->id,
            'company_id' => $company->id,
            'email' => 'yes@x.ru',
            'marketing_consent' => true,
        ]);
        ClientContact::factory()->role(ClientContactRole::BUYER)->create([
            'user_id' => $partner->id,
            'company_id' => $company->id,
            'email' => 'no@x.ru',
            'marketing_consent' => false,
        ]);

        $campaign = NotificationCampaign::create($this->payload() + ['status' => NotificationCampaign::STATUS_DRAFT]);

        $this->actingAs($this->manager)
            ->post("/crm/notifications/campaigns/{$campaign->id}/audience")
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Получателей: 1')
                && str_contains($m, 'Нет согласия на рассылки'));
    }

    #[Test]
    #[TestDox('Менеджер не может запустить рассылку')]
    public function manager_cannot_send(): void
    {
        $campaign = $this->campaignWithAudience();

        $this->actingAs($this->manager)
            ->post("/crm/notifications/campaigns/{$campaign->id}/send")
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Руководитель запускает рассылку')]
    public function head_sends_campaign(): void
    {
        $campaign = $this->campaignWithAudience();

        $this->actingAs($this->head)
            ->post("/crm/notifications/campaigns/{$campaign->id}/send")
            ->assertRedirect();

        $this->assertSame(NotificationCampaign::STATUS_SENT, $campaign->fresh()->status);
    }

    #[Test]
    #[TestDox('Рассылка без собранной аудитории не запускается')]
    public function empty_audience_is_refused(): void
    {
        $campaign = NotificationCampaign::create($this->payload() + ['status' => NotificationCampaign::STATUS_DRAFT]);

        $this->actingAs($this->head)
            ->post("/crm/notifications/campaigns/{$campaign->id}/send")
            ->assertStatus(422);
    }

    #[Test]
    #[TestDox('Отправленную рассылку править нельзя')]
    public function sent_campaign_is_locked(): void
    {
        $campaign = NotificationCampaign::create($this->payload() + ['status' => NotificationCampaign::STATUS_SENT]);

        $this->actingAs($this->manager)
            ->patch("/crm/notifications/campaigns/{$campaign->id}", $this->payload())
            ->assertStatus(422);
    }

    #[Test]
    #[TestDox('Раздел открывается и показывает шаблоны и роли')]
    public function index_opens(): void
    {
        $this->actingAs($this->manager)
            ->get('/crm/notifications/campaigns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Pages/Notifications/Campaigns')
                ->has('roles')
                ->where('canSend', false)
            );
    }
}
