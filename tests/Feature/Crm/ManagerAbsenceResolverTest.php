<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\ManagerAbsenceType;
use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use App\Services\Crm\ManagerAbsenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerAbsenceResolverTest extends TestCase
{
    use RefreshDatabase;

    private ManagerAbsenceResolver $resolver;

    private PersonalManager $manager;

    private PersonalManager $substitute;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(ManagerAbsenceResolver::class);
        $this->manager = PersonalManager::factory()->create(['name' => 'Курочкина Елена']);
        $this->substitute = PersonalManager::factory()->create(['name' => 'Сухов Иван']);
    }

    public function test_returns_substitute_during_absence(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => $this->substitute->id,
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addDay(),
        ]);

        $this->assertSame(
            $this->substitute->id,
            $this->resolver->effectiveManager($this->manager)->id,
        );
    }

    public function test_returns_manager_outside_of_absence_period(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => $this->substitute->id,
            'starts_on' => today()->addDays(3),
            'ends_on' => today()->addDays(10),
        ]);

        $this->assertSame(
            $this->manager->id,
            $this->resolver->effectiveManager($this->manager)->id,
        );
    }

    public function test_period_bounds_are_inclusive(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => $this->substitute->id,
            'starts_on' => today(),
            'ends_on' => today(),
        ]);

        $this->assertSame(
            $this->substitute->id,
            $this->resolver->effectiveManager($this->manager)->id,
        );
        $this->assertSame(
            $this->manager->id,
            $this->resolver->effectiveManager($this->manager, today()->addDay())->id,
        );
        $this->assertSame(
            $this->manager->id,
            $this->resolver->effectiveManager($this->manager, today()->subDay())->id,
        );
    }

    public function test_absence_without_substitute_keeps_manager(): void
    {
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => null,
            'type' => ManagerAbsenceType::TRUANCY,
            'starts_on' => today(),
            'ends_on' => today(),
        ]);

        $this->assertSame(
            $this->manager->id,
            $this->resolver->effectiveManager($this->manager)->id,
        );
        $this->assertFalse($this->resolver->resolve($this->manager)->isSubstitution());
    }

    public function test_resolution_carries_absent_manager_and_until_date(): void
    {
        // Дата окончания относительная: с календарной отсутствие однажды
        // заканчивается само, замещения не остаётся и тест падает не из-за кода.
        $endsOn = today()->addDays(4);

        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'substitute_manager_id' => $this->substitute->id,
            'starts_on' => today()->subDays(2),
            'ends_on' => $endsOn,
        ]);

        $resolution = $this->resolver->resolve($this->manager);

        $this->assertTrue($resolution->isSubstitution());
        $this->assertSame($this->substitute->id, $resolution->manager->id);
        $this->assertSame($this->manager->id, $resolution->absentManager->id);
        $this->assertSame($endsOn->format('d.m.Y'), $resolution->until->format('d.m.Y'));
    }
}
