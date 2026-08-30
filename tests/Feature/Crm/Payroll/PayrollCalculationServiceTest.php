<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Payroll\PayrollCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PayrollCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private User $head;

    private Carbon $month;

    private PayrollCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->manager = PersonalManager::factory()->create();
        $this->head = User::factory()->create();
        $this->month = Carbon::now()->startOfMonth();
        $this->service = app(PayrollCalculationService::class);
    }

    #[Test]
    #[TestDox('Черновик создаётся один раз и при тех же входах не переписывается')]
    public function draft_is_created_once_and_reused(): void
    {
        $first = $this->service->recalculateDraft($this->manager->id, $this->month, 'test');

        $this->assertNotNull($first);
        $this->assertSame(PayrollCalculation::STATUS_DRAFT, $first->status);
        $this->assertSame(1, $first->version);
        $this->assertSame(70000.0, (float) $first->total);   // оклад; премии нет — плана нет
        $this->assertNotEmpty($first->breakdown['warnings']);
        $this->assertNotNull($first->inputs_hash);

        $computedAt = $first->computed_at;
        $this->travel(2)->minutes();

        $second = $this->service->recalculateDraft($this->manager->id, $this->month, 'test');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PayrollCalculation::query()->count());
        $this->assertTrue($second->computed_at->greaterThan($computedAt));
        $this->assertSame($first->breakdown, $second->breakdown);
    }

    #[Test]
    #[TestDox('Изменившиеся входы меняют итог черновика')]
    public function changed_inputs_update_the_draft(): void
    {
        $this->service->recalculateDraft($this->manager->id, $this->month);

        PayrollManualAdjustment::factory()->forMonth($this->month)->create(['personal_manager_id' => $this->manager->id]);

        $draft = $this->service->recalculateDraft($this->manager->id, $this->month);

        $this->assertSame(75000.0, (float) $draft->total);
        $this->assertSame(1, PayrollCalculation::query()->count());
    }

    #[Test]
    #[TestDox('Утверждённый снимок заморожен: пересчёт его не трогает')]
    public function approved_snapshot_is_frozen(): void
    {
        $draft = $this->service->recalculateDraft($this->manager->id, $this->month);
        $approved = $this->service->approve($draft, $this->head, 'проверено');

        $this->assertSame(PayrollCalculation::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->head->id, $approved->approved_by_user_id);
        $this->assertTrue($approved->isFrozen());

        PayrollManualAdjustment::factory()->forMonth($this->month)->create(['personal_manager_id' => $this->manager->id]);

        $this->assertNull($this->service->recalculateDraft($this->manager->id, $this->month));
        $this->assertSame(70000.0, (float) $this->service->current($this->manager->id, $this->month)->total);
        $this->assertSame($approved->id, $this->service->ensureDraft($this->manager->id, $this->month)->id);
    }

    #[Test]
    #[TestDox('Переоткрытие создаёт новую версию черновика, старая остаётся утверждённой')]
    public function reopen_creates_next_version(): void
    {
        $draft = $this->service->recalculateDraft($this->manager->id, $this->month);
        $this->service->approve($draft, $this->head);

        PayrollManualAdjustment::factory()->forMonth($this->month)->create(['personal_manager_id' => $this->manager->id]);

        $reopened = $this->service->reopen($draft->fresh(), $this->head, 'доначислить ТГ');

        $this->assertSame(2, $reopened->version);
        $this->assertTrue($reopened->isDraft());
        $this->assertSame(75000.0, (float) $reopened->total);
        $this->assertStringContainsString('доначислить ТГ', (string) $reopened->comment);

        $this->assertSame($reopened->id, $this->service->current($this->manager->id, $this->month)->id);
        $this->assertSame(PayrollCalculation::STATUS_APPROVED, $draft->fresh()->status);
        $this->assertSame(2, PayrollCalculation::query()->count());
    }

    #[Test]
    #[TestDox('«Выплачено» ставится только на утверждённый')]
    public function paid_requires_approved(): void
    {
        $draft = $this->service->recalculateDraft($this->manager->id, $this->month);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->markPaid($draft, $this->head);
    }

    #[Test]
    #[TestDox('Утверждённый → выплачен; переоткрыть черновик нельзя')]
    public function paid_flow_and_guards(): void
    {
        $draft = $this->service->recalculateDraft($this->manager->id, $this->month);
        $this->service->approve($draft, $this->head);

        $paid = $this->service->markPaid($draft->fresh(), $this->head);
        $this->assertSame(PayrollCalculation::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->paid_at);

        $fresh = $this->service->recalculateDraft($this->manager->id, $this->month->copy()->subMonth());
        $this->expectException(\InvalidArgumentException::class);
        $this->service->reopen($fresh, $this->head);
    }

    #[Test]
    #[TestDox('Открытые черновики прошлых месяцев и залежавшиеся черновики находятся')]
    public function open_and_stale_drafts(): void
    {
        $previous = $this->month->copy()->subMonth();
        $this->service->recalculateDraft($this->manager->id, $previous);
        $current = $this->service->recalculateDraft($this->manager->id, $this->month);

        $this->assertSame([$previous->toDateString(), $this->month->toDateString()], $this->service->openDraftMonths($this->manager->id));

        $this->assertCount(0, $this->service->staleDrafts($this->month, 10));

        $current->forceFill(['computed_at' => now()->subMinutes(30)])->save();
        $this->assertCount(1, $this->service->staleDrafts($this->month, 10));

        $this->service->approve($current->fresh(), $this->head);
        $this->assertSame([$previous->toDateString()], $this->service->openDraftMonths($this->manager->id));
    }
}
