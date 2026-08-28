<?php

namespace Tests\Feature\Console;

use App\Enums\Crm\TaskStatus;
use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\CrmAgentToken;
use App\Models\CrmTask;
use App\Models\CrmTaskRecurrence;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Разовая передача дел РОП (crm:rop-handover): Астапенко → Елисеев.
 */
class CrmRopHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $newRop;

    private User $oldRop;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->newRop = User::factory()->create([
            'name' => 'Большая Красная Шляпа',
            'email' => 'paxa333@gmail.com',
        ]);
        $this->oldRop = User::factory()->create([
            'name' => 'Астапенко Игорь',
            'email' => 'salesdir@andrey-company.ru',
            'user_kind' => UserKind::STAFF->value,
        ]);
        $this->oldRop->assignRole('sales-head');

        $this->card = PersonalManager::factory()->create([
            'erp_uuid' => null,
            'name' => 'Астапенко Игорь',
            'email' => 'salesdir@andrey-company.ru',
            'user_id' => $this->oldRop->id,
            'is_active' => true,
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->artisan('crm:rop-handover')->assertSuccessful();

        $this->assertSame('Большая Красная Шляпа', $this->newRop->fresh()->name);
        $this->assertSame('Астапенко Игорь', $this->card->fresh()->name);
        $this->assertTrue($this->oldRop->fresh()->hasRole('sales-head'));
    }

    public function test_apply_transfers_card_tasks_and_blocks_old_rop(): void
    {
        $client = User::factory()->create(['personal_manager_id' => $this->card->id]);

        $openTask = CrmTask::factory()->create([
            'assignee_id' => $this->oldRop->id,
            'status' => TaskStatus::OPEN->value,
        ]);
        $doneTask = CrmTask::factory()->create([
            'assignee_id' => $this->oldRop->id,
            'status' => TaskStatus::DONE->value,
        ]);
        $recurrence = CrmTaskRecurrence::create([
            'author_id' => $this->oldRop->id,
            'assignee_id' => $this->oldRop->id,
            'title' => 'Ежедневный отчёт по дебиторке',
            'priority' => 'normal',
            'weekdays' => [1, 2, 3, 4, 5],
            'due_time' => '17:00',
            'starts_on' => today(),
            'is_active' => true,
        ]);
        $token = CrmAgentToken::create([
            'name' => 'Агент РОП',
            'user_id' => $this->oldRop->id,
            'token' => str_repeat('a', 64),
            'is_active' => true,
        ]);

        $this->artisan('crm:rop-handover', ['--apply' => true])->assertSuccessful();

        $newRop = $this->newRop->fresh();
        $this->assertSame('Елисеев Павел', $newRop->name);
        $this->assertSame('Елисеев Павел', $newRop->erp_name);
        $this->assertSame(UserKind::STAFF, $newRop->user_kind);
        $this->assertTrue($newRop->hasRole('sales-head'));

        $card = $this->card->fresh();
        $this->assertSame('Елисеев Павел', $card->name);
        $this->assertSame('sales@pecado.ru', $card->email);
        $this->assertSame($newRop->id, $card->user_id);

        // Клиенты остались на той же карточке — переезд не нужен.
        $this->assertSame($card->id, $client->fresh()->personal_manager_id);

        // Открытая задача и шаблон переехали, закрытая осталась в истории.
        $this->assertSame($newRop->id, $openTask->fresh()->assignee_id);
        $this->assertSame($newRop->id, $recurrence->fresh()->assignee_id);
        $this->assertSame($this->oldRop->id, $doneTask->fresh()->assignee_id);

        $oldRop = $this->oldRop->fresh();
        $this->assertSame(UserStatus::BLOCKED, $oldRop->status);
        $this->assertFalse($oldRop->hasRole('sales-head'));
        $this->assertFalse((bool) $token->fresh()->is_active);
    }

    public function test_apply_is_idempotent(): void
    {
        $this->artisan('crm:rop-handover', ['--apply' => true])->assertSuccessful();
        $this->artisan('crm:rop-handover', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Елисеев Павел', $this->newRop->fresh()->name);
        $this->assertSame($this->newRop->id, $this->card->fresh()->user_id);
        $this->assertSame(UserStatus::BLOCKED, $this->oldRop->fresh()->status);
    }
}
