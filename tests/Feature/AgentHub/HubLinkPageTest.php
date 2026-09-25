<?php

namespace Tests\Feature\AgentHub;

use App\Models\AgentHubLink;
use App\Models\AgentTopic;
use App\Models\AgentTopicMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Пульт Agent Hub по ссылке-хешу: открыт без авторизации тому, кому выдали
 * ссылку (админ сайта, админ 1С), и умеет то же, что админка.
 *
 * Проверяем главное: доступ держится только на токене — отозванная ссылка
 * закрывает и просмотр, и управление.
 */
class HubLinkPageTest extends TestCase
{
    use RefreshDatabase;

    private AgentHubLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->link = AgentHubLink::factory()->create(['label' => 'Админ 1С']);
    }

    private function hub(string $path = ''): string
    {
        return "/agent-hub/{$this->link->token}{$path}";
    }

    #[Test]
    public function guest_with_link_sees_topics_list(): void
    {
        AgentTopic::factory()->count(2)->create();

        $this->get($this->hub())
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('AgentHub/Index')
                ->where('hub.label', 'Админ 1С')
                ->has('topics.data', 2));
    }

    #[Test]
    public function unknown_and_revoked_links_are_not_found(): void
    {
        $this->get('/agent-hub/'.str_repeat('a', 64))->assertNotFound();

        $revoked = AgentHubLink::factory()->revoked()->create();

        $this->get("/agent-hub/{$revoked->token}")->assertNotFound();
        $this->post("/agent-hub/{$revoked->token}/topics", [
            'title' => 'Тест',
            'task_body' => 'Тело',
        ])->assertNotFound();
    }

    #[Test]
    public function visit_marks_link_as_used(): void
    {
        $this->assertNull($this->link->last_used_at);

        $this->get($this->hub())->assertOk();

        $this->assertNotNull($this->link->fresh()->last_used_at);
    }

    #[Test]
    public function topic_page_shows_both_agent_links(): void
    {
        $topic = AgentTopic::factory()->create();

        $this->get($this->hub("/topics/{$topic->id}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('AgentHub/Show')
                ->where('topic.site_url', url("/api/agent-hub/{$topic->site_token}"))
                ->where('topic.erp_url', url("/api/agent-hub/{$topic->erp_token}")));
    }

    #[Test]
    public function guest_with_link_creates_topic(): void
    {
        $response = $this->post($this->hub('/topics'), [
            'title' => 'Сверка остатков',
            'task_body' => 'Сверить остатки по складу.',
        ]);

        $topic = AgentTopic::firstOrFail();

        $response->assertRedirect($this->hub("/topics/{$topic->id}"));

        $this->assertSame('Сверка остатков', $topic->title);
        $this->assertSame($this->link->id, $topic->hub_link_id);
        $this->assertSame('Админ 1С', $topic->created_by_agent);
    }

    #[Test]
    public function topic_creation_requires_title_and_task(): void
    {
        $this->post($this->hub('/topics'), ['title' => '', 'task_body' => ''])
            ->assertSessionHasErrors(['title', 'task_body']);

        $this->assertSame(0, AgentTopic::count());
    }

    #[Test]
    public function moderator_message_is_signed_with_link_label(): void
    {
        $topic = AgentTopic::factory()->create();

        $this->post($this->hub("/topics/{$topic->id}/messages"), ['body' => 'Сверяйте по UUID склада.'])
            ->assertRedirect();

        $message = AgentTopicMessage::firstOrFail();

        $this->assertSame(AgentTopicMessage::AUTHOR_MODERATOR, $message->author);
        $this->assertSame(['via' => 'Админ 1С'], $message->payload);
        // Сообщение модератора идёт вне очереди: ход остаётся у той же стороны.
        $this->assertSame(AgentTopic::ROLE_SITE, $topic->fresh()->turn);
    }

    #[Test]
    public function task_update_notifies_agents_with_system_message(): void
    {
        $topic = AgentTopic::factory()->create();

        $this->put($this->hub("/topics/{$topic->id}"), [
            'title' => 'Новое название',
            'task_body' => 'Новая постановка задачи.',
        ])->assertRedirect();

        $this->assertSame('Новое название', $topic->fresh()->title);
        $this->assertSame(
            AgentTopicMessage::AUTHOR_SYSTEM,
            AgentTopicMessage::where('topic_id', $topic->id)->firstOrFail()->author,
        );
    }

    #[Test]
    public function turn_can_be_passed_and_topic_closed(): void
    {
        $topic = AgentTopic::factory()->create();

        $this->post($this->hub("/topics/{$topic->id}/pass-turn"))->assertRedirect();
        $this->assertSame(AgentTopic::ROLE_ERP, $topic->fresh()->turn);

        $this->post($this->hub("/topics/{$topic->id}/close"), ['resolution' => 'Расхождений нет.'])
            ->assertRedirect();

        $topic->refresh();
        $this->assertSame(AgentTopic::STATUS_CLOSED, $topic->status);
        $this->assertSame('Расхождений нет.', $topic->resolution);

        // В закрытый топик агенты писать уже не могут — проверяем через их API.
        $this->postJson("/api/agent-hub/{$topic->site_token}/messages", ['body' => 'Ещё реплика'])
            ->assertStatus(409);
    }
}
