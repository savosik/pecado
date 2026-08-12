<?php

namespace Tests\Feature\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Impersonation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Полоска «кто сейчас на сайте» (crm-23).
 */
class PresenceTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $ownCard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->ownCard = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    #[Test]
    public function only_clients_active_inside_the_window_are_listed(): void
    {
        $online = User::factory()->create([
            'personal_manager_id' => $this->ownCard->id,
            'last_seen_at' => now()->subMinute(),
        ]);

        User::factory()->create([
            'personal_manager_id' => $this->ownCard->id,
            'last_seen_at' => now()->subHour(),
        ]);

        User::factory()->create([
            'personal_manager_id' => $this->ownCard->id,
            'last_seen_at' => null,
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.presence'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('clients.0.id', $online->id);
    }

    #[Test]
    public function presence_honours_the_scope(): void
    {
        User::factory()->create([
            'personal_manager_id' => $this->ownCard->id,
            'last_seen_at' => now(),
        ]);

        $foreignCard = PersonalManager::factory()->create();
        User::factory()->create([
            'personal_manager_id' => $foreignCard->id,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.presence'))
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs($this->manager)
            ->getJson(route('crm.presence', ['scope' => 'department']))
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    #[Test]
    public function manager_without_department_permission_sees_only_own_clients(): void
    {
        $this->restrictManagersToOwnClients();

        $foreignCard = PersonalManager::factory()->create();
        User::factory()->create([
            'personal_manager_id' => $foreignCard->id,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.presence', ['scope' => 'department']))
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    /**
     * «+N» должно говорить, сколько людей не поместилось, а не сколько строк
     * вернул запрос: иначе на большом отделе полоска врёт про охват.
     */
    #[Test]
    public function the_overflow_counter_counts_everyone_not_just_the_page(): void
    {
        config(['crm.presence.limit' => 2]);

        User::factory()->count(5)->create([
            'personal_manager_id' => $this->ownCard->id,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('crm.presence'))
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonCount(2, 'clients');
    }

    /**
     * Вход менеджера под клиентом не должен рисовать клиента «на сайте»:
     * иначе полоска показывала бы отделу его собственные действия.
     */
    #[Test]
    public function impersonation_does_not_mark_a_client_online(): void
    {
        $client = User::factory()->create([
            'personal_manager_id' => $this->ownCard->id,
            'last_seen_at' => null,
        ]);

        Impersonation::start($this->manager);

        $this->actingAs($client)->get('/');

        Impersonation::stop();

        $this->assertNull($client->fresh()->last_seen_at);
    }
}
