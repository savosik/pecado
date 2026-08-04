<?php

namespace Tests\Feature\Crm;

use App\Enums\UserKind;
use App\Models\CrmClientStatusChange;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Состав отдела: кто считается клиентом и какие карточки менеджеров рабочие.
 *
 * 1С присылает партнёрами всех подряд — закупщиков, собственных сотрудников,
 * технические учётки — и заводит карточку менеджера каждому, кого хоть раз
 * указали в документе. Чистить это должен тот, кто отвечает за отдел, не открывая
 * админку и не дожидаясь разработчика.
 */
class ClientRosterTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $this->managerProfile->id]);
    }

    // --- Тип аккаунта -------------------------------------------------------

    #[Test]
    public function head_removes_account_from_client_base(): void
    {
        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $this->client->id), [
                'user_kind' => 'staff',
                'reason' => 'Закупщик, сам не покупает',
            ])
            ->assertRedirect(route('crm.clients.index'));

        $this->assertSame(UserKind::STAFF, $this->client->fresh()->user_kind);

        // Из клиентской выборки CRM аккаунт выпадает сразу.
        $this->assertFalse(
            User::query()->visibleInCrm($this->head)->whereKey($this->client->id)->exists(),
        );
    }

    #[Test]
    public function kind_change_is_written_to_the_status_journal(): void
    {
        $this->actingAs($this->head)->put(route('crm.clients.kind.update', $this->client->id), [
            'user_kind' => 'service',
            'reason' => 'Тестовая учётка',
        ]);

        $entry = CrmClientStatusChange::query()
            ->where('field', CrmClientStatusChange::FIELD_KIND)
            ->firstOrFail();

        $this->assertSame($this->client->id, $entry->client_user_id);
        $this->assertSame('client', $entry->from_value);
        $this->assertSame('service', $entry->to_value);
        $this->assertSame($this->head->id, $entry->user_id);
        $this->assertSame('Тестовая учётка', $entry->reason);
    }

    #[Test]
    public function account_can_be_returned_to_the_client_base(): void
    {
        $this->client->update(['user_kind' => UserKind::STAFF->value]);

        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $this->client->id), ['user_kind' => 'client'])
            ->assertRedirect();

        $this->assertSame(UserKind::CLIENT, $this->client->fresh()->user_kind);
        $this->assertTrue(
            User::query()->visibleInCrm($this->head)->whereKey($this->client->id)->exists(),
        );
    }

    #[Test]
    public function manager_cannot_change_kind_of_own_client(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.clients.kind.update', $this->client->id), ['user_kind' => 'staff'])
            ->assertForbidden();

        $this->assertSame(UserKind::CLIENT, $this->client->fresh()->user_kind);
    }

    #[Test]
    public function account_without_manager_is_out_of_reach(): void
    {
        // Лид или служебная учётка вне отдела — это не клиентская база, ими
        // занимается админка.
        $lead = User::factory()->create(['personal_manager_id' => null]);

        $this->actingAs($this->head)
            ->put(route('crm.clients.kind.update', $lead->id), ['user_kind' => 'staff'])
            ->assertNotFound();
    }

    // --- Карточки менеджеров ------------------------------------------------

    #[Test]
    public function head_hides_and_restores_a_manager_card(): void
    {
        $this->actingAs($this->head)
            ->put(route('crm.team.active', $this->managerProfile->id), ['is_active' => false])
            ->assertRedirect();

        $this->assertFalse($this->managerProfile->fresh()->is_active);

        $this->actingAs($this->head)
            ->put(route('crm.team.active', $this->managerProfile->id), ['is_active' => true])
            ->assertRedirect();

        $this->assertTrue($this->managerProfile->fresh()->is_active);
    }

    #[Test]
    public function hidden_card_disappears_from_crm_selectors(): void
    {
        $junk = PersonalManager::factory()->create(['name' => 'Дубль из 1С', 'is_active' => false]);

        // Фильтр списка клиентов.
        $this->actingAs($this->head)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'managers',
                fn ($managers) => collect($managers)->doesntContain('id', $junk->id),
            ));

        // Сетка планов и переключатель скоупа на вкладке «Выполнение».
        $this->actingAs($this->head)
            ->get(route('crm.plans.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('managers', fn ($rows) => collect($rows)->doesntContain('id', $junk->id))
                ->where('managerOptions', fn ($rows) => collect($rows)->doesntContain('id', $junk->id)));

        $options = $this->actingAs($this->head)
            ->getJson(route('crm.plans.progress'))
            ->assertOk()
            ->json('scopeOptions');

        $this->assertNotContains($junk->id, array_column($options, 'id'));
    }

    #[Test]
    public function hidden_card_still_shows_on_the_team_page(): void
    {
        $this->managerProfile->update(['is_active' => false]);

        $this->actingAs($this->head)
            ->get(route('crm.team.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canEdit', true)
                ->where('managers', fn ($rows) => collect($rows)
                    ->firstWhere('id', $this->managerProfile->id)['is_active'] === false));
    }

    #[Test]
    public function manager_cannot_hide_a_card(): void
    {
        $this->actingAs($this->manager)
            ->put(route('crm.team.active', $this->managerProfile->id), ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($this->managerProfile->fresh()->is_active);
    }
}
