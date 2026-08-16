<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\BusinessType;
use App\Models\CrmClientProfile;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Галочка «страховой запас» на клиенте (карточка buf-02).
 *
 * Инварианты: меняет только менеджер с crm-profile.edit, смена журналируется
 * («кто и когда»), анкета даёт лишь рекомендацию — не автопроставление.
 */
class StockBufferFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    private function toggle(bool $enabled, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->manager)->put(
            route('crm.clients.stock-buffer.update', $this->client),
            ['enabled' => $enabled],
        );
    }

    #[Test]
    public function enabling_writes_flag_and_journal(): void
    {
        $this->toggle(true)->assertRedirect();

        $this->assertTrue($this->client->refresh()->stock_buffer_enabled);
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->client->id,
            'field' => 'stock_buffer',
            'from_value' => '0',
            'to_value' => '1',
            'user_id' => $this->manager->id,
        ]);
    }

    #[Test]
    public function disabling_writes_flag_and_journal(): void
    {
        $this->client->forceFill(['stock_buffer_enabled' => true])->save();

        $this->toggle(false)->assertRedirect();

        $this->assertFalse($this->client->refresh()->stock_buffer_enabled);
        $this->assertDatabaseHas('crm_client_status_changes', [
            'client_user_id' => $this->client->id,
            'field' => 'stock_buffer',
            'from_value' => '1',
            'to_value' => '0',
        ]);
    }

    #[Test]
    public function same_value_does_not_pollute_the_journal(): void
    {
        $this->toggle(false)->assertRedirect();

        $this->assertDatabaseCount('crm_client_status_changes', 0);
    }

    #[Test]
    public function employee_without_profile_edit_gets_403(): void
    {
        $role = Role::create(['name' => 'crm-viewer']);
        $role->givePermissionTo(Permission::findByName('crm-dashboard.view'));

        $viewer = User::factory()->create();
        $viewer->assignRole($role);

        $this->toggle(true, $viewer)->assertForbidden();
        $this->assertFalse($this->client->refresh()->stock_buffer_enabled);
    }

    #[Test]
    public function online_business_type_is_a_recommendation_not_automation(): void
    {
        CrmClientProfile::create([
            'user_id' => $this->client->id,
            'business_type' => BusinessType::ONLINE,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Анкета online → бейдж-рекомендация, но сама ничего не включает
                ->where('client.stock_buffer.recommended', true)
                ->where('client.stock_buffer.enabled', false));

        $this->assertFalse($this->client->refresh()->stock_buffer_enabled);
    }

    #[Test]
    public function marketplace_flag_also_recommends(): void
    {
        CrmClientProfile::create([
            'user_id' => $this->client->id,
            'business_type' => BusinessType::OFFLINE,
            'works_with_marketplaces' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('client.stock_buffer.recommended', true));
    }

    #[Test]
    public function list_filter_round_trips_and_filters(): void
    {
        $flagged = User::factory()->create([
            'personal_manager_id' => $this->client->personal_manager_id,
        ]);
        $flagged->forceFill(['stock_buffer_enabled' => true])->save();

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index', ['stock_buffer' => 'enabled']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Ключ фильтра обязан возвращаться в снимке filters: иначе
                // следующий applyFilters молча сбросит отбор (round-trip-правило).
                ->where('filters.stock_buffer', 'enabled')
                ->where('clients.total', 1)
                ->where('clients.data.0.id', $flagged->id)
                ->where('clients.data.0.stock_buffer_enabled', true));
    }
}
