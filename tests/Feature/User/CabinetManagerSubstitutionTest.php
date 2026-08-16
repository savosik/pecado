<?php

namespace Tests\Feature\User;

use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Замещение менеджера в дашборде кабинета клиента (abs-01).
 */
class CabinetManagerSubstitutionTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private PersonalManager $substitute;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = PersonalManager::factory()->create([
            'name' => 'Курочкина Елена',
            'phone' => '+7 (901) 782-28-32',
            'email' => 'b2b@pecado.ru',
        ]);
        $this->substitute = PersonalManager::factory()->create([
            'name' => 'Сухов Иван',
            'phone' => '+7 (901) 782-28-35',
            'email' => 'opt@pecado.ru',
        ]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->manager->id]);
    }

    public function test_dashboard_shows_substitute_contacts_during_absence(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => $this->substitute->id,
            'starts_on' => today(),
            'ends_on' => '2026-08-31',
        ]);

        $this->actingAs($this->client)
            ->get(route('cabinet.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('personalManager.name', 'Сухов Иван')
                ->where('personalManager.phone', '+7 (901) 782-28-35')
                ->where('personalManager.email', 'opt@pecado.ru')
                ->where('personalManager.substitution.absent_manager_name', 'Курочкина Елена')
                ->where('personalManager.substitution.until', '31.08.2026')
            );

        // Привязка клиента не изменилась — подмена только на чтении.
        $this->assertSame($this->manager->id, $this->client->fresh()->personal_manager_id);
    }

    public function test_dashboard_shows_own_manager_after_absence_ends(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => $this->substitute->id,
            'starts_on' => today()->subDays(10),
            'ends_on' => today()->subDay(),
        ]);

        $this->actingAs($this->client)
            ->get(route('cabinet.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('personalManager.name', 'Курочкина Елена')
                ->where('personalManager.substitution', null)
            );
    }

    public function test_dashboard_without_absence_has_no_substitution_note(): void
    {
        $this->actingAs($this->client)
            ->get(route('cabinet.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('personalManager.name', 'Курочкина Елена')
                ->where('personalManager.substitution', null)
            );
    }
}
