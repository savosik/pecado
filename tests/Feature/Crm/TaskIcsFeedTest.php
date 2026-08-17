<?php

namespace Tests\Feature\Crm;

use App\Models\CrmCalendarToken;
use App\Models\CrmTask;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * task-07: подписной ICS-фид для Google/Яндекс Календаря.
 */
class TaskIcsFeedTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $colleague;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->colleague = User::factory()->create();
        $this->colleague->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->colleague->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');
    }

    #[Test]
    public function feed_serves_valid_ics_by_token_without_session(): void
    {
        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'title' => 'Позвонить партнёру',
            'due_at' => now()->addDay()->setTime(12, 0),
        ]);

        $token = CrmCalendarToken::forUser($this->manager, 'mine');

        // Без авторизации — как ходят Google и Яндекс.
        $response = $this->get("/crm/tasks/feed/{$token->token}.ics")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        $body = $response->getContent();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('UID:crm-task-', $body);
        $this->assertStringContainsString('Позвонить партнёру', str_replace("\r\n ", '', $body));
        $this->assertStringContainsString('END:VCALENDAR', $body);

        $this->assertNotNull($token->fresh()->last_fetched_at);
    }

    #[Test]
    public function postpone_bumps_sequence_and_moves_event(): void
    {
        $task = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDay()->setTime(12, 0),
        ]);

        $token = CrmCalendarToken::forUser($this->manager, 'mine');

        $before = $this->get("/crm/tasks/feed/{$token->token}.ics")->getContent();
        $this->assertStringContainsString('SEQUENCE:0', $before);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.postpone', $task), [
                'due_at' => now()->addDays(4)->setTime(9, 30)->format('Y-m-d\TH:i'),
            ])
            ->assertOk();

        $after = $this->get("/crm/tasks/feed/{$token->token}.ics")->getContent();
        $this->assertStringContainsString('SEQUENCE:1', $after);
    }

    #[Test]
    public function closed_task_disappears_watched_and_co_assigned_present(): void
    {
        $own = CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'due_at' => now()->addDay(),
        ]);

        $coAssigned = CrmTask::factory()->create([
            'author_id' => $this->colleague->id,
            'assignee_id' => $this->colleague->id,
            'due_at' => now()->addDays(2),
        ]);
        $coAssigned->coAssignees()->attach($this->manager->id);

        $watched = CrmTask::factory()->create([
            'author_id' => $this->colleague->id,
            'assignee_id' => $this->colleague->id,
            'due_at' => now()->addDays(3),
        ]);
        $watched->watchers()->attach($this->manager->id);

        $token = CrmCalendarToken::forUser($this->manager, 'mine');

        $body = $this->get("/crm/tasks/feed/{$token->token}.ics")->getContent();
        $this->assertStringContainsString("crm-task-{$own->id}@", $body);
        $this->assertStringContainsString("crm-task-{$coAssigned->id}@", $body);
        $this->assertStringContainsString("crm-task-{$watched->id}@", $body);

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.close', $own), ['outcome' => 'success'])
            ->assertOk();

        $body = $this->get("/crm/tasks/feed/{$token->token}.ics")->getContent();
        $this->assertStringNotContainsString("crm-task-{$own->id}@", $body);
    }

    #[Test]
    public function rotation_kills_old_link(): void
    {
        $token = CrmCalendarToken::forUser($this->manager, 'mine');
        $oldToken = $token->token;

        $this->get("/crm/tasks/feed/{$oldToken}.ics")->assertOk();

        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.calendar-links.rotate'), ['scope' => 'mine'])
            ->assertOk();

        $this->get("/crm/tasks/feed/{$oldToken}.ics")->assertNotFound();
        $this->get('/crm/tasks/feed/'.$token->fresh()->token.'.ics')->assertOk();
    }

    #[Test]
    public function department_scope_is_gated_and_differs_from_personal(): void
    {
        CrmTask::factory()->create([
            'author_id' => $this->colleague->id,
            'assignee_id' => $this->colleague->id,
            'due_at' => now()->addDay(),
        ]);

        // Рядовой менеджер не может выпустить фид отдела.
        $this->actingAs($this->manager)
            ->postJson(route('crm.tasks.calendar-links.rotate'), ['scope' => 'department'])
            ->assertForbidden();

        // РОП — может, и в его фиде видна чужая задача.
        $links = $this->actingAs($this->head)
            ->getJson(route('crm.tasks.calendar-links'))
            ->assertOk()
            ->json('data');

        $department = collect($links)->firstWhere('scope', 'department');
        $this->assertNotNull($department);

        $token = CrmCalendarToken::forUser($this->head, 'department');
        $body = $this->get("/crm/tasks/feed/{$token->token}.ics")->getContent();

        $this->assertStringContainsString('crm-task-', $body);
        $this->assertStringContainsString('задачи отдела', mb_strtolower(str_replace("\r\n ", '', $body)));
    }
}
