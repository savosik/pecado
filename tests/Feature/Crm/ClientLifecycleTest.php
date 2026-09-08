<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\CrmClientProfile;
use App\Models\CrmClientStatusChange;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

class ClientLifecycleTest extends TestCase
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

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    private function change(string $status, ?string $reason = null, ?User $client = null)
    {
        return $this->actingAs($this->manager)->put(
            route('crm.clients.lifecycle.update', $client ?? $this->client),
            array_filter(['lifecycle_status' => $status, 'reason' => $reason]),
        );
    }

    #[Test]
    public function status_change_is_written_to_the_journal(): void
    {
        $this->change('sleeping', 'Не отвечает третий месяц')->assertRedirect();

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame(ClientLifecycleStatus::SLEEPING, $profile->lifecycle_status);
        $this->assertSame($this->manager->id, $profile->lifecycle_changed_by);
        $this->assertNotNull($profile->lifecycle_changed_at);

        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->client->id,
            'field' => 'lifecycle',
            'from_value' => null,
            'to_value' => 'sleeping',
            'user_id' => $this->manager->id,
            'reason' => 'Не отвечает третий месяц',
        ]);
    }

    #[Test]
    public function journal_remembers_where_the_client_came_from(): void
    {
        $this->change('sleeping');
        $this->change('churned', 'Закрылись');

        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->client->id,
            'from_value' => 'sleeping',
            'to_value' => 'churned',
        ]);
        $this->assertDatabaseCount('crm_client_status_changes', 2);
    }

    #[Test]
    public function repeating_the_same_status_does_not_pollute_the_journal(): void
    {
        $this->change('sleeping');
        $this->change('sleeping');

        $this->assertDatabaseCount('crm_client_status_changes', 1);
    }

    #[Test]
    public function history_is_visible_in_the_client_card(): void
    {
        $this->change('in_work', 'Начали работать');

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lifecycle.status', 'in_work')
                ->where('lifecycle.status_label', 'В работе')
                ->where('lifecycle.history.0.to', 'В работе')
                ->where('lifecycle.history.0.reason', 'Начали работать')
                ->where('lifecycle.history.0.author', $this->manager->name)
            );
    }

    #[Test]
    public function client_without_profile_reads_as_active(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lifecycle.status', 'active')
                ->where('lifecycle.history', [])
                ->where('lifecycle.hint', null)
            );
    }

    #[Test]
    public function loyalty_status_has_no_write_route(): void
    {
        // Лояльностью владеет 1С: HandlePartnerUpdated перезапишет её следующим
        // сообщением, поэтому редактировать её в CRM нельзя вовсе.
        $this->assertNull(app('router')->getRoutes()->getByName('crm.clients.loyalty.update'));
    }

    #[Test]
    public function foreign_client_status_change_returns_404(): void
    {
        $otherProfile = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $otherProfile->id]);

        $this->change('churned', null, $foreign)->assertNotFound();
        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    public function unknown_status_gives_a_russian_validation_error(): void
    {
        $response = $this->actingAs($this->manager)
            ->from(route('crm.clients.show', $this->client))
            ->put(route('crm.clients.lifecycle.update', $this->client), ['lifecycle_status' => 'заморожен']);

        $response->assertSessionHasErrors('lifecycle_status');
        $this->assertSame('Такого жизненного статуса нет.', session('errors')->first('lifecycle_status'));
    }

    #[Test]
    public function list_can_be_filtered_by_lifecycle_stage(): void
    {
        $sleeping = User::factory()->create(['personal_manager_id' => $this->client->personal_manager_id]);
        CrmClientProfile::factory()->create([
            'user_id' => $sleeping->id,
            'lifecycle_status' => ClientLifecycleStatus::SLEEPING,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['lifecycle' => 'sleeping']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients.total', 1)
                ->where('clients.data.0.id', $sleeping->id)
            );

        // Клиент без профиля должен попадать в «Активен» — иначе фильтр
        // спрятал бы всех, кого ещё никто не описывал.
        $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['lifecycle' => 'active']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients.total', 1)
                ->where('clients.data.0.id', $this->client->id)
            );
    }

    #[Test]
    public function hints_command_suggests_sleeping_but_never_changes_the_status(): void
    {
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_created_at' => now()->subDays(120),
        ]);

        $this->artisan('crm:lifecycle-hints')->assertSuccessful();

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame(ClientLifecycleStatus::SLEEPING, $profile->lifecycle_hint);
        $this->assertSame('нет отгрузок 90 дней', $profile->lifecycle_hint_reason);
        // Главное требование карточки: статус остаётся прежним.
        $this->assertSame(ClientLifecycleStatus::ACTIVE, $profile->lifecycle_status);
        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    public function hints_command_counts_business_date_not_created_at(): void
    {
        // Историю 1С импортировали разом, поэтому created_at у всей базы свежий,
        // а реальная дата документа — в erp_created_at.
        $shipment = Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_created_at' => now()->subDays(200),
        ]);
        $shipment->forceFill(['created_at' => now()])->save();

        $this->artisan('crm:lifecycle-hints')->assertSuccessful();

        $this->assertSame(
            ClientLifecycleStatus::SLEEPING,
            CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail()->lifecycle_hint,
        );
    }

    #[Test]
    public function recent_shipment_leaves_the_client_without_a_hint(): void
    {
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_created_at' => now()->subDays(5),
        ]);

        $this->artisan('crm:lifecycle-hints')->assertSuccessful();

        $this->assertDatabaseCount('crm_client_profiles', 0);
    }

    #[Test]
    public function sleeping_client_with_a_fresh_shipment_is_suggested_back_to_active(): void
    {
        CrmClientProfile::factory()->create([
            'user_id' => $this->client->id,
            'lifecycle_status' => ClientLifecycleStatus::SLEEPING,
        ]);
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_created_at' => now()->subDays(3),
        ]);

        $this->artisan('crm:lifecycle-hints')->assertSuccessful();

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame(ClientLifecycleStatus::ACTIVE, $profile->lifecycle_hint);
        $this->assertSame(ClientLifecycleStatus::SLEEPING, $profile->lifecycle_status);
    }

    #[Test]
    public function changing_the_status_removes_the_hint(): void
    {
        CrmClientProfile::factory()->create([
            'user_id' => $this->client->id,
            'lifecycle_hint' => ClientLifecycleStatus::SLEEPING,
            'lifecycle_hint_reason' => 'нет отгрузок 90 дней',
            'lifecycle_hint_at' => now(),
        ]);

        $this->change('sleeping', 'нет отгрузок 90 дней')->assertRedirect();

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertNull($profile->lifecycle_hint);
        $this->assertNull($profile->lifecycle_hint_reason);
        $this->assertNull($profile->lifecycle_hint_at);
    }

    #[Test]
    #[TestDox('Причина ухода — отдельная стадия: «Банкрот» доезжает до профиля и журнала')]
    public function reason_of_leaving_is_a_stage_of_its_own(): void
    {
        $this->change('bankrupt', 'Единственный контрагент признан банкротом')->assertRedirect();

        $profile = CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame(ClientLifecycleStatus::BANKRUPT, $profile->lifecycle_status);
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->client->id,
            'to_value' => 'bankrupt',
            'reason' => 'Единственный контрагент признан банкротом',
        ]);
    }

    #[Test]
    #[TestDox('Из терминальной стадии система сама не поднимает, из «Риска ухода» — предлагает')]
    public function terminal_stages_are_never_revived_by_the_night_command(): void
    {
        $atRisk = User::factory()->create(['personal_manager_id' => $this->client->personal_manager_id]);
        CrmClientProfile::factory()->create([
            'user_id' => $atRisk->id,
            'lifecycle_status' => ClientLifecycleStatus::AT_RISK,
        ]);
        CrmClientProfile::factory()->create([
            'user_id' => $this->client->id,
            'lifecycle_status' => ClientLifecycleStatus::BANKRUPT,
        ]);

        foreach ([$atRisk, $this->client] as $client) {
            Shipment::factory()->create(['user_id' => $client->id, 'erp_created_at' => now()->subDays(3)]);
        }

        $this->artisan('crm:lifecycle-hints')->assertSuccessful();

        $this->assertSame(
            ClientLifecycleStatus::ACTIVE,
            CrmClientProfile::query()->where('user_id', $atRisk->id)->firstOrFail()->lifecycle_hint,
        );
        $this->assertNull(
            CrmClientProfile::query()->where('user_id', $this->client->id)->firstOrFail()->lifecycle_hint,
        );
    }

    #[Test]
    #[TestDox('История читает снятые стадии по словарю, а не сырым значением')]
    public function history_labels_retired_stages(): void
    {
        // Такие записи остались в журнале с 08.08.2026 — переписывать историю нельзя.
        CrmClientStatusChange::create([
            'client_user_id' => $this->client->id,
            'field' => 'lifecycle',
            'from_value' => 'active',
            'to_value' => 'hopeless',
            'user_id' => $this->manager->id,
            'reason' => 'Импорт таблицы продаж',
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('lifecycle.history.0.to', 'Непреодолимо (устар.)'));
    }

    #[Test]
    #[TestDox('Варианты стадий приезжают на фронт группами')]
    public function stages_reach_the_front_grouped(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('profileOptions.lifecycle_status.0.value', 'lead')
                ->where('profileOptions.lifecycle_status.0.group_label', 'Работаем с партнёром')
                ->where('profileOptions.lifecycle_status.8.value', 'churned')
                ->where('profileOptions.lifecycle_status.8.group_label', 'Больше не покупает')
            );
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'erp_created_at' => now()->subDays(150),
        ]);

        $this->artisan('crm:lifecycle-hints --dry-run')->assertSuccessful();

        $this->assertDatabaseCount('crm_client_profiles', 0);
    }
}
