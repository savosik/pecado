<?php

namespace Tests\Feature\Admin;

use App\Models\AgentTopic;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Админка диалогов ИИ-агентов: создание топиков, модераторские действия.
 */
class AgentTopicsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    #[Test]
    public function admin_can_open_topics_list(): void
    {
        AgentTopic::factory()->count(2)->create();

        $this->actingAs($this->admin())
            ->get('/admin/agent-topics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/AgentTopics/Index')
                ->has('topics.data', 2));
    }

    #[Test]
    public function user_without_permission_cannot_open_list(): void
    {
        $user = User::factory()->create();
        $user->assignRole('content-manager');

        $this->actingAs($user)->get('/admin/agent-topics')->assertForbidden();
    }

    #[Test]
    public function admin_creates_topic_and_gets_both_links(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/admin/agent-topics', [
            'title' => 'Сверка балансов',
            'task_body' => 'Сверить балансы контрагентов за август.',
        ]);

        $topic = AgentTopic::sole();
        $response->assertRedirect(route('admin.agent-topics.show', $topic));

        $this->assertSame($admin->id, $topic->created_by);
        $this->assertNotEmpty($topic->site_token);
        $this->assertNotEmpty($topic->erp_token);
        $this->assertNotSame($topic->site_token, $topic->erp_token);
        $this->assertSame(AgentTopic::ROLE_SITE, $topic->turn);

        $this->actingAs($admin)
            ->get(route('admin.agent-topics.show', $topic))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/AgentTopics/Show')
                ->where('topic.site_url', url("/api/agent-hub/{$topic->site_token}"))
                ->where('topic.erp_url', url("/api/agent-hub/{$topic->erp_token}")));
    }

    #[Test]
    public function moderator_message_does_not_pass_turn(): void
    {
        $topic = AgentTopic::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.agent-topics.messages.store', $topic), ['body' => 'Уточнение от модератора.'])
            ->assertRedirect();

        $topic->refresh();
        $this->assertSame(AgentTopic::ROLE_SITE, $topic->turn);
        $this->assertSame(1, $topic->last_seq);
        $this->assertSame('moderator', $topic->messages()->sole()->author);
    }

    #[Test]
    public function pass_turn_flips_turn_and_logs_system_message(): void
    {
        $topic = AgentTopic::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.agent-topics.pass-turn', $topic))
            ->assertRedirect();

        $topic->refresh();
        $this->assertSame(AgentTopic::ROLE_ERP, $topic->turn);
        $this->assertSame('system', $topic->messages()->sole()->author);
    }

    #[Test]
    public function close_finishes_topic_for_agents(): void
    {
        $topic = AgentTopic::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.agent-topics.close', $topic))
            ->assertRedirect();

        $this->assertSame(AgentTopic::STATUS_CLOSED, $topic->fresh()->status);

        // Агент после закрытия получает отказ
        $this->postJson("/api/agent-hub/{$topic->site_token}/messages", ['body' => 'Ещё работаю…'])
            ->assertStatus(409);
    }
}
