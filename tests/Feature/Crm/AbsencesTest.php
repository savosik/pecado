<?php

namespace Tests\Feature\Crm;

use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Раздел CRM «Отсутствия и замещения» (abs-02): доступы и валидации.
 */
class AbsencesTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    private PersonalManager $kurochkina;

    private PersonalManager $suhov;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager-crm');

        $this->kurochkina = PersonalManager::factory()->create([
            'name' => 'Курочкина Елена',
            'user_id' => User::factory()->create()->id,
            'is_active' => true,
        ]);
        $this->suhov = PersonalManager::factory()->create([
            'name' => 'Сухов Иван',
            'email' => 'opt@pecado.ru',
            'user_id' => User::factory()->create()->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'personal_manager_id' => $this->kurochkina->id,
            'substitute_manager_id' => $this->suhov->id,
            'type' => 'vacation',
            'starts_on' => today()->toDateString(),
            'ends_on' => '2026-08-31',
            'comment' => null,
            ...$overrides,
        ];
    }

    public function test_head_creates_absence(): void
    {
        $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('manager_absences', [
            'personal_manager_id' => $this->kurochkina->id,
            'substitute_manager_id' => $this->suhov->id,
            'type' => 'vacation',
            'created_by' => $this->head->id,
        ]);
    }

    public function test_manager_sees_index_but_cannot_create(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.absences.index'))
            ->assertOk();

        $this->actingAs($this->manager)
            ->post(route('crm.absences.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_guest_and_client_have_no_access(): void
    {
        $this->get(route('crm.absences.index'))->assertRedirect(route('login'));

        $client = User::factory()->create();
        $this->actingAs($client)
            ->get(route('crm.absences.index'))
            ->assertRedirect('/');
    }

    public function test_rejects_overlapping_period_for_same_manager(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->kurochkina->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(5),
        ]);

        $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload([
                'starts_on' => today()->addDays(3)->toDateString(),
                'ends_on' => today()->addDays(10)->toDateString(),
            ]))
            ->assertSessionHasErrors('starts_on');
    }

    public function test_rejects_self_substitution(): void
    {
        $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload([
                'substitute_manager_id' => $this->kurochkina->id,
            ]))
            ->assertSessionHasErrors('substitute_manager_id');
    }

    public function test_rejects_substitute_who_is_away_himself(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->suhov->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(3),
        ]);

        $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload())
            ->assertSessionHasErrors('substitute_manager_id');
    }

    public function test_rejects_absence_for_active_substitute(): void
    {
        // Сухов уже назначен замещающим Курочкиной — сам уйти в этот период не может.
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->kurochkina->id,
            'substitute_manager_id' => $this->suhov->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(10),
        ]);

        $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload([
                'personal_manager_id' => $this->suhov->id,
                'substitute_manager_id' => null,
                'starts_on' => today()->addDays(2)->toDateString(),
                'ends_on' => today()->addDays(4)->toDateString(),
            ]))
            ->assertSessionHasErrors('personal_manager_id');
    }

    public function test_rejects_hidden_substitute_card(): void
    {
        $hidden = PersonalManager::factory()->create(['name' => 'Скрытый', 'is_active' => false]);

        $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload([
                'substitute_manager_id' => $hidden->id,
            ]))
            ->assertSessionHasErrors('substitute_manager_id');
    }

    public function test_rejects_reversed_dates(): void
    {
        $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload([
                'starts_on' => today()->addDays(5)->toDateString(),
                'ends_on' => today()->toDateString(),
            ]))
            ->assertSessionHasErrors('ends_on');
    }

    public function test_warns_when_substitute_has_no_email(): void
    {
        $noEmail = PersonalManager::factory()->create([
            'name' => 'Без Почты',
            'email' => null,
            'user_id' => User::factory()->create()->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->head)
            ->post(route('crm.absences.store'), $this->payload([
                'substitute_manager_id' => $noEmail->id,
            ]));

        $response->assertRedirect()->assertSessionHas('success');
        $this->assertStringContainsString('нет email', session('success'));
    }

    public function test_finish_shifts_end_date_to_yesterday(): void
    {
        $absence = ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->kurochkina->id,
            'substitute_manager_id' => $this->suhov->id,
            'starts_on' => today()->subDays(5),
            'ends_on' => today()->addDays(10),
        ]);

        $this->actingAs($this->head)
            ->put(route('crm.absences.finish', $absence))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($absence->fresh()->ends_on->isSameDay(today()->subDay()));
    }

    public function test_finish_rejects_not_started_absence(): void
    {
        $absence = ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->kurochkina->id,
            'starts_on' => today()->addDays(3),
            'ends_on' => today()->addDays(10),
        ]);

        $this->actingAs($this->head)
            ->put(route('crm.absences.finish', $absence))
            ->assertSessionHasErrors('absence');
    }

    public function test_destroy_removes_record(): void
    {
        $absence = ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->kurochkina->id,
        ]);

        $this->actingAs($this->head)
            ->delete(route('crm.absences.destroy', $absence))
            ->assertRedirect();

        $this->assertDatabaseMissing('manager_absences', ['id' => $absence->id]);
    }

    public function test_manager_cannot_finish_or_destroy(): void
    {
        $absence = ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->kurochkina->id,
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addDay(),
        ]);

        $this->actingAs($this->manager)
            ->put(route('crm.absences.finish', $absence))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->delete(route('crm.absences.destroy', $absence))
            ->assertForbidden();
    }
}
