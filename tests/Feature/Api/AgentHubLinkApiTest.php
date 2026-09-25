<?php

namespace Tests\Feature\Api;

use App\Models\AgentHubLink;
use App\Models\AgentTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Agent Hub — заведение топиков внешними агентами по ссылке-хешу.
 *
 * Ключевое: ретрай с тем же external_key не плодит топики (правило «один
 * домен — один рабочий топик»), а отозванная ссылка закрывает API целиком.
 */
class AgentHubLinkApiTest extends TestCase
{
    use RefreshDatabase;

    private AgentHubLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->link = AgentHubLink::factory()->create(['label' => 'Агент 1С']);
    }

    private function url(string $path = ''): string
    {
        return "/api/agent-hub/links/{$this->link->token}{$path}";
    }

    #[Test]
    public function discovery_describes_how_to_create_topic(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('link.label', 'Агент 1С')
            ->assertJsonPath('link.hub_url', url("/agent-hub/{$this->link->token}"))
            ->assertJsonStructure(['endpoints' => ['discovery', 'topics', 'topic', 'create'], 'rules']);
    }

    #[Test]
    public function external_agent_creates_topic_and_gets_agent_links(): void
    {
        $response = $this->postJson($this->url('/topics'), [
            'title' => 'Круг 14: сверка взаиморасчётов',
            'task_body' => 'Сверить регистр взаиморасчётов с витриной сайта.',
            'agent_name' => 'Агент 1С',
        ])->assertCreated();

        $topic = AgentTopic::firstOrFail();

        $response
            ->assertJsonPath('topic.id', $topic->id)
            ->assertJsonPath('topic.site_agent_url', url("/api/agent-hub/{$topic->site_token}"))
            ->assertJsonPath('topic.erp_agent_url', url("/api/agent-hub/{$topic->erp_token}"))
            ->assertJsonPath('topic.hub_url', url("/agent-hub/{$this->link->token}/topics/{$topic->id}"))
            ->assertJsonPath('repeated', false);

        $this->assertSame($this->link->id, $topic->hub_link_id);
        $this->assertSame('Агент 1С', $topic->created_by_agent);
        // Ссылки агентов рабочие сразу: точка входа отдаёт постановку задачи.
        $this->getJson("/api/agent-hub/{$topic->erp_token}")
            ->assertOk()
            ->assertJsonPath('you', AgentTopic::ROLE_ERP);
    }

    #[Test]
    public function repeated_external_key_returns_same_topic(): void
    {
        $payload = [
            'title' => 'Сверка остатков',
            'task_body' => 'Сверить остатки.',
            'external_key' => 'stock-sync-2026-09',
        ];

        $first = $this->postJson($this->url('/topics'), $payload)->assertCreated();
        $second = $this->postJson($this->url('/topics'), $payload)->assertOk();

        $this->assertSame(1, AgentTopic::count());
        $this->assertSame(
            $first->json('topic.id'),
            $second->json('topic.id'),
        );
        $second->assertJsonPath('repeated', true);
    }

    #[Test]
    public function creator_can_keep_the_first_turn(): void
    {
        $this->postJson($this->url('/topics'), [
            'title' => 'Вопрос по предзаказам',
            'task_body' => 'Уточнить UUID склада.',
            'turn' => 'erp',
        ])->assertCreated()->assertJsonPath('topic.turn', 'erp');

        $this->assertSame(AgentTopic::ROLE_ERP, AgentTopic::firstOrFail()->turn);
    }

    #[Test]
    public function title_and_task_body_are_required(): void
    {
        $this->postJson($this->url('/topics'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'task_body']);
    }

    #[Test]
    public function topics_list_and_single_topic_are_available(): void
    {
        $topic = AgentTopic::factory()->create(['title' => 'Сверка остатков', 'status' => AgentTopic::STATUS_IN_PROGRESS]);
        AgentTopic::factory()->create(['title' => 'Другое', 'status' => AgentTopic::STATUS_CLOSED]);

        $this->getJson($this->url('/topics?status=in_progress'))
            ->assertOk()
            ->assertJsonCount(1, 'topics')
            ->assertJsonPath('topics.0.title', 'Сверка остатков');

        $this->getJson($this->url("/topics/{$topic->id}"))
            ->assertOk()
            ->assertJsonPath('topic.task_body', $topic->task_body)
            ->assertJsonStructure(['topic', 'messages']);
    }

    #[Test]
    public function revoked_link_closes_the_api(): void
    {
        $this->link->forceFill(['revoked_at' => now()])->save();

        $this->getJson($this->url())->assertNotFound();
        $this->postJson($this->url('/topics'), [
            'title' => 'Тест',
            'task_body' => 'Тело',
        ])->assertNotFound();

        $this->assertSame(0, AgentTopic::count());
    }
}
