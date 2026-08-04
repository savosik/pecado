<?php

namespace Tests\Feature\Crm;

use App\Models\CrmClientFilterPreset;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Личные отборы списка клиентов.
 *
 * Отбор — личная настройка, поэтому доступ гейтится связью, а не политикой:
 * чужой отбор не находится вовсе и отвечает 404.
 */
class ClientFilterPresetsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    #[Test]
    public function manager_saves_and_sees_own_preset(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.clients.presets.store'), [
                'name' => 'Горящие',
                'payload' => ['task_state' => 'overdue', 'sort_by' => 'next_task_due', 'sort_order' => 'asc'],
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Горящие')
            ->assertJsonPath('payload.task_state', 'overdue');

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('presets.0.name', 'Горящие'));
    }

    #[Test]
    public function preset_payload_is_sanitized_before_saving(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.clients.presets.store'), [
                'name' => 'Мусорный',
                'payload' => [
                    'task_state' => 'выдуманное',
                    'sort_by' => 'password',
                    'lifecycle' => 'sleeping',
                    'посторонний_ключ' => 'значение',
                ],
            ])
            ->assertCreated()
            // Значения вне белого списка не сохраняются — иначе отбор вернул бы
            // их в запрос при следующем применении.
            ->assertJsonPath('payload.task_state', null)
            ->assertJsonPath('payload.sort_by', 'id')
            ->assertJsonPath('payload.lifecycle', 'sleeping')
            ->assertJsonMissingPath('payload.посторонний_ключ');
    }

    #[Test]
    public function manager_cannot_delete_foreign_preset(): void
    {
        $stranger = User::factory()->create();
        $preset = CrmClientFilterPreset::create([
            'user_id' => $stranger->id,
            'name' => 'Чужой',
            'payload' => [],
        ]);

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.clients.presets.destroy', $preset->id))
            ->assertNotFound();

        $this->assertDatabaseHas('crm_client_filter_presets', ['id' => $preset->id]);
    }

    #[Test]
    public function manager_deletes_own_preset(): void
    {
        $preset = CrmClientFilterPreset::create([
            'user_id' => $this->manager->id,
            'name' => 'Свой',
            'payload' => [],
        ]);

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.clients.presets.destroy', $preset->id))
            ->assertNoContent();

        $this->assertDatabaseMissing('crm_client_filter_presets', ['id' => $preset->id]);
    }

    #[Test]
    public function preset_requires_crm_clients_view(): void
    {
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-tasks.view');

        $this->actingAs($outsider)
            ->postJson(route('crm.clients.presets.store'), ['name' => 'X', 'payload' => []])
            ->assertForbidden();
    }

    #[Test]
    public function preset_name_is_required(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.clients.presets.store'), ['payload' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}
