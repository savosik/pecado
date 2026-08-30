<?php

namespace Tests\Feature\Crm\Payroll;

use App\Events\Payroll\PayrollInputsChanged;
use App\Jobs\Payroll\RecalculatePayrollDraft;
use App\Models\CrmSalesPlan;
use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PayrollRecalculationTriggerTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private User $client;

    private string $current;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->manager = PersonalManager::factory()->create();
        $this->client = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        $this->current = Carbon::now()->startOfMonth()->toDateString();
    }

    #[Test]
    #[TestDox('Событие входов ставит пересчёт текущего месяца с задержкой')]
    public function event_schedules_current_month(): void
    {
        PayrollInputsChanged::dispatch([$this->manager->id], 'test');

        Queue::assertPushed(RecalculatePayrollDraft::class, 1);
        Queue::assertPushed(RecalculatePayrollDraft::class, fn (RecalculatePayrollDraft $job): bool => $job->managerId === $this->manager->id
            && $job->month === $this->current
            && $job->delay !== null);
    }

    #[Test]
    #[TestDox('Открытый черновик прошлого месяца тоже пересчитывается, будущий месяц — нет')]
    public function open_past_draft_is_included_future_is_not(): void
    {
        $previous = Carbon::now()->startOfMonth()->subMonth()->toDateString();
        $next = Carbon::now()->startOfMonth()->addMonth()->toDateString();

        PayrollCalculation::factory()->forMonth($previous)->create(['personal_manager_id' => $this->manager->id]);
        // startOfMonth до вычитания: 31 августа минус два месяца Carbon переносит
        // на 1 июля, и утверждённый снимок сталкивался с черновиком прошлого месяца.
        PayrollCalculation::factory()->forMonth(Carbon::now()->startOfMonth()->subMonths(2))->approved()->create(['personal_manager_id' => $this->manager->id]);

        PayrollInputsChanged::dispatch([$this->manager->id], 'test', [$next]);

        Queue::assertPushed(RecalculatePayrollDraft::class, 2);
        Queue::assertPushed(RecalculatePayrollDraft::class, fn (RecalculatePayrollDraft $job): bool => $job->month === $previous);
        Queue::assertPushed(RecalculatePayrollDraft::class, fn (RecalculatePayrollDraft $job): bool => $job->month === $this->current);
        Queue::assertNotPushed(RecalculatePayrollDraft::class, fn (RecalculatePayrollDraft $job): bool => $job->month === $next);
    }

    #[Test]
    #[TestDox('Новая реализация партнёра будит черновик его менеджера')]
    public function shipment_triggers_recalculation(): void
    {
        Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->client->id,
            'date' => now()->toDateString(),
            'erp_created_at' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
        ]);

        Queue::assertPushed(RecalculatePayrollDraft::class, fn (RecalculatePayrollDraft $job): bool => $job->managerId === $this->manager->id
            && $job->month === $this->current
            && $job->source === 'shipment.created');
    }

    #[Test]
    #[TestDox('План партнёра будит черновик; повтор по той же паре дедуплицируется; план отдела — нет')]
    public function plans_trigger_recalculation(): void
    {
        CrmSalesPlan::factory()->forMonth(Carbon::now())->forClient($this->client)->create();
        Queue::assertPushed(RecalculatePayrollDraft::class, 1);

        // Та же пара менеджер × месяц: замок уникальности ещё держится — это и есть дебаунс.
        CrmSalesPlan::factory()->forMonth(Carbon::now())->forManager($this->manager)->create();
        Queue::assertPushed(RecalculatePayrollDraft::class, 1);

        CrmSalesPlan::factory()->forMonth(Carbon::now())->create();   // отдел — в формулу не входит
        Queue::assertPushed(RecalculatePayrollDraft::class, 1);

        // Другой менеджер — другой замок.
        $other = PersonalManager::factory()->create();
        CrmSalesPlan::factory()->forMonth(Carbon::now())->forManager($other)->create();
        Queue::assertPushed(RecalculatePayrollDraft::class, 2);
    }

    #[Test]
    #[TestDox('Джоб пересчёта уникален до начала обработки — пачка событий даёт один расчёт')]
    public function job_is_unique_until_processing(): void
    {
        $job = new RecalculatePayrollDraft($this->manager->id, $this->current, 'test');

        $this->assertSame('payroll-draft:'.$this->manager->id.':'.$this->current, $job->uniqueId());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing::class, $job);
    }
}
