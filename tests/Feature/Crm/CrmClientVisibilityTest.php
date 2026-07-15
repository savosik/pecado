<?php

namespace Tests\Feature\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CrmClientVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $managerA;

    private User $managerB;

    private PersonalManager $profileA;

    private PersonalManager $profileB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->managerA = User::factory()->create();
        $this->managerA->assignRole('sales-manager');
        $this->profileA = PersonalManager::factory()->create(['user_id' => $this->managerA->id]);

        $this->managerB = User::factory()->create();
        $this->managerB->assignRole('sales-manager');
        $this->profileB = PersonalManager::factory()->create(['user_id' => $this->managerB->id]);
    }

    private function clientsOf(PersonalManager $profile, int $count): void
    {
        User::factory()->count($count)->create(['personal_manager_id' => $profile->id]);
    }

    private function salesHead(): User
    {
        $user = User::factory()->create();
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

        $admin = User::factory()->create();
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

        $orphan = User::factory()->create();
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
    public function client_without_manager_is_not_listed_for_sales_head(): void
    {
        $this->clientsOf($this->profileA, 2);
        // Лид: пользователь без закреплённого менеджера.
        User::factory()->create(['personal_manager_id' => null]);

        $this->actingAs($this->salesHead())
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 2));
    }
}
