<?php

namespace Tests\Feature\Debt;

use App\Enums\DebtLevel;
use App\Events\DebtLevelChanged;
use App\Events\DebtPauseExpired;
use App\Listeners\CreateDebtTasks;
use App\Models\Company;
use App\Models\CrmTask;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Автозадачи лестницы долга: кому, на что, без дублей.
 */
class DebtTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['debt.enabled' => true, 'debt.mode' => 'live', 'debt.live_actions' => 'tasks']);

        $this->manager = User::factory()->create();
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $card->id, 'erp_name' => 'Гевея ООО']);
        $this->company = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Литвиненко ИП']);
    }

    #[Test]
    public function no_orders_creates_call_task_for_manager_once(): void
    {
        $state = $this->state(DebtLevel::NO_ORDERS);

        $this->fire($state, DebtLevel::NO_PREORDERS, DebtLevel::NO_ORDERS);
        $this->fire($state, DebtLevel::NO_PREORDERS, DebtLevel::NO_ORDERS);

        $tasks = CrmTask::query()->where('title', 'like', 'Позвонить:%')->get();
        $this->assertCount(1, $tasks);
        $this->assertSame($this->manager->id, $tasks->first()->assignee_id);
        $this->assertSame($this->client->id, $tasks->first()->client_user_id);
        $this->assertStringContainsString('Литвиненко', $tasks->first()->title);
    }

    #[Test]
    public function hold_creates_pretrial_task(): void
    {
        $state = $this->state(DebtLevel::HOLD);

        $this->fire($state, DebtLevel::NO_ORDERS, DebtLevel::HOLD);

        $task = CrmTask::query()->where('title', 'like', 'Досудебная претензия:%')->firstOrFail();
        $this->assertSame('high', $task->priority->value);
        $this->assertStringContainsString('вручную', $task->description);
    }

    #[Test]
    public function relief_and_soft_steps_create_nothing(): void
    {
        $state = $this->state(DebtLevel::OVERDUE);
        $this->fire($state, DebtLevel::CLEAN, DebtLevel::OVERDUE);
        $this->fire($state, DebtLevel::NO_ORDERS, DebtLevel::OVERDUE);

        $this->assertSame(0, CrmTask::query()->count());
    }

    #[Test]
    public function expired_pause_goes_back_to_its_author(): void
    {
        $author = User::factory()->create();
        $pause = DebtPause::create([
            'user_id' => $this->client->id,
            'until' => now()->subDay()->toDateString(),
            'reason' => 'Обещал оплатить до вчера',
            'created_by' => $author->id,
            'released_at' => now(),
            'released_reason' => DebtPause::RELEASED_EXPIRED,
        ]);

        app(CreateDebtTasks::class)->handle(new DebtPauseExpired($pause));

        $task = CrmTask::query()->where('title', 'like', 'Разблокировка истекла:%')->firstOrFail();
        $this->assertSame($author->id, $task->assignee_id);
        $this->assertStringContainsString('Гевея', $task->title);
    }

    #[Test]
    public function active_pause_means_no_call_task(): void
    {
        DebtPause::create([
            'user_id' => $this->client->id,
            'until' => now()->addDays(10)->toDateString(),
            'reason' => 'Договорённость есть',
            'created_by' => $this->manager->id,
        ]);
        $state = $this->state(DebtLevel::NO_ORDERS);
        $this->fire($state, DebtLevel::CLEAN, DebtLevel::NO_ORDERS);

        $this->assertSame(0, CrmTask::query()->count());
    }

    #[Test]
    public function tasks_action_off_means_silence(): void
    {
        config(['debt.live_actions' => 'mail']);
        $state = $this->state(DebtLevel::HOLD);
        $this->fire($state, DebtLevel::NO_ORDERS, DebtLevel::HOLD);

        $this->assertSame(0, CrmTask::query()->count());
    }

    private function state(DebtLevel $level): DebtState
    {
        return DebtState::create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'level' => $level,
            'since' => now()->toDateString(),
            'overdue_amount' => 1370102,
            'overdue_total' => 1370102,
            'oldest_due_date' => now()->subDays(166)->toDateString(),
            'age_days' => 166,
            'lines_count' => 13,
            'reason' => 'тест',
            'dry_run' => false,
            'computed_at' => now(),
        ])->fresh(['user', 'company']);
    }

    private function fire(DebtState $state, DebtLevel $from, DebtLevel $to): void
    {
        app(CreateDebtTasks::class)->handle(new DebtLevelChanged($state, $from, $to));
    }
}
