<?php

namespace Tests\Feature\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

class CrmClientVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $managerA;

    private User $managerB;

    private PersonalManager $profileA;

    private PersonalManager $profileB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->managerA = User::factory()->staff()->create();
        $this->managerA->assignRole('sales-manager');
        $this->profileA = PersonalManager::factory()->create(['user_id' => $this->managerA->id]);

        $this->managerB = User::factory()->staff()->create();
        $this->managerB->assignRole('sales-manager');
        $this->profileB = PersonalManager::factory()->create(['user_id' => $this->managerB->id]);
    }

    private function clientsOf(PersonalManager $profile, int $count): void
    {
        User::factory()->count($count)->create(['personal_manager_id' => $profile->id]);
    }

    // Сотрудники — staff, как на проде: иначе РОП, видящий нераспределённых,
    // посчитал бы лидом собственную учётку.
    private function salesHead(): User
    {
        $user = User::factory()->staff()->create();
        $user->assignRole('sales-head');

        return $user;
    }

    #[Test]
    public function manager_sees_only_own_clients(): void
    {
        $this->clientsOf($this->profileA, 3);
        $this->clientsOf($this->profileB, 5);

        $this->actingAs($this->managerA)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Clients/Index')
                ->where('clients.total', 3)
            );
    }

    #[Test]
    public function manager_cannot_open_foreign_client_card(): void
    {
        $foreign = User::factory()->create(['personal_manager_id' => $this->profileB->id]);

        $this->actingAs($this->managerA)
            ->get(route('crm.clients.show', $foreign->id))
            ->assertNotFound();
    }

    #[Test]
    public function manager_can_open_own_client_card(): void
    {
        $own = User::factory()->create(['personal_manager_id' => $this->profileA->id]);

        $this->actingAs($this->managerA)
            ->get(route('crm.clients.show', $own->id))
            ->assertOk();
    }

    #[Test]
    public function manager_cannot_see_foreign_clients_by_forging_manager_id(): void
    {
        $this->clientsOf($this->profileA, 2);
        $this->clientsOf($this->profileB, 4);

        // Подстановка чужого manager_id не должна ничего открыть.
        $this->actingAs($this->managerA)
            ->get(route('crm.clients.index', ['manager_id' => $this->profileB->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 2)
                ->where('canSeeAll', false)
            );
    }

    #[Test]
    public function sales_head_sees_all_department_clients(): void
    {
        $this->clientsOf($this->profileA, 3);
        $this->clientsOf($this->profileB, 5);

        $this->actingAs($this->salesHead())
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 8)
                ->where('canSeeAll', true)
            );
    }

    #[Test]
    public function sales_head_can_filter_by_manager(): void
    {
        $this->clientsOf($this->profileA, 3);
        $this->clientsOf($this->profileB, 5);

        $this->actingAs($this->salesHead())
            ->get(route('crm.clients.index', ['manager_id' => $this->profileB->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 5));
    }

    #[Test]
    public function sales_head_without_manager_profile_still_sees_everyone(): void
    {
        // Страхует порядок веток в scopeVisibleInCrm: право на весь отдел
        // должно проверяться раньше managerProfile.
        $this->clientsOf($this->profileA, 3);

        $head = $this->salesHead();
        $this->assertNull($head->managerProfile);

        $this->actingAs($head)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 3));
    }

    #[Test]
    public function super_admin_sees_all_clients(): void
    {
        $this->clientsOf($this->profileA, 3);
        $this->clientsOf($this->profileB, 5);

        $admin = User::factory()->staff()->create();
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 8));
    }

    #[Test]
    public function manager_without_profile_sees_empty_list_not_error(): void
    {
        $this->clientsOf($this->profileA, 3);

        $orphan = User::factory()->staff()->create();
        $orphan->assignRole('sales-manager');

        $this->actingAs($orphan)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 0)
                ->where('managerProfileLinked', false)
            );
    }

    #[Test]
    public function client_without_manager_is_hidden_until_head_opts_in(): void
    {
        $this->clientsOf($this->profileA, 2);
        // Лид: партнёр без закреплённого менеджера. С v16.10.0 распределяет
        // РОП из CRM, но по умолчанию хвост не показывается никому.
        User::factory()->create(['personal_manager_id' => null]);

        $this->actingAs($this->salesHead())
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 2));
    }

    #[Test]
    public function client_without_manager_is_listed_for_sales_head_with_checkbox_on(): void
    {
        $this->clientsOf($this->profileA, 2);
        User::factory()->create(['personal_manager_id' => null]);

        $head = $this->salesHead();
        $head->forceFill(['crm_show_unassigned' => true])->save();

        $this->actingAs($head)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 3));
    }

    #[Test]
    public function checkbox_is_switched_through_preferences_endpoint(): void
    {
        $head = $this->salesHead();

        $this->actingAs($head)
            ->put(route('crm.preferences.unassigned'), ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue($head->fresh()->crm_show_unassigned);

        $this->actingAs($head)
            ->put(route('crm.preferences.unassigned'), ['enabled' => false])
            ->assertRedirect();

        $this->assertFalse($head->fresh()->crm_show_unassigned);
    }

    #[Test]
    public function manager_without_department_right_cannot_switch_checkbox(): void
    {
        // Менеджеры в этом тесте лишены crm-department.view: без права на отдел
        // показывать нераспределённых нечего, и endpoint закрыт.
        $this->actingAs($this->managerA)
            ->put(route('crm.preferences.unassigned'), ['enabled' => true])
            ->assertForbidden();
    }

    #[Test]
    public function sales_head_can_filter_clients_without_manager(): void
    {
        $this->clientsOf($this->profileA, 2);
        $lead = User::factory()->create(['personal_manager_id' => null]);

        $head = $this->salesHead();
        $head->forceFill(['crm_show_unassigned' => true])->save();

        $this->actingAs($head)
            ->get(route('crm.clients.index', ['manager_id' => 'none']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.total', 1)
                ->where('clients.data.0.id', $lead->id)
                ->where('filters.manager_id', 'none'));
    }

    #[Test]
    public function manager_does_not_see_clients_without_manager(): void
    {
        $this->clientsOf($this->profileA, 2);
        $lead = User::factory()->create(['personal_manager_id' => null]);

        // Даже с включённой галочкой: без права на отдел она ничего не открывает.
        $this->managerA->forceFill(['crm_show_unassigned' => true])->save();

        $this->actingAs($this->managerA)
            ->get(route('crm.clients.index', ['manager_id' => 'none']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 2));

        $this->actingAs($this->managerA)
            ->get(route('crm.clients.show', $lead->id))
            ->assertNotFound();
    }
}
