<?php

namespace Tests\Feature\Api;

use App\Models\AgentTopic;
use App\Models\AgentTopicMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Agent Hub — API совместной работы ИИ-агентов (сайт ↔ 1С).
 *
 * Ключевое здесь — серверная очерёдность: сообщение вне хода отклоняется,
 * ретраи не создают дублей, закрытие — только рукопожатием proposal → resolution.
 */
class AgentHubApiTest extends TestCase
{
    use RefreshDatabase;

    private AgentTopic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->topic = AgentTopic::factory()->create([
            'title' => 'Сверка остатков',
            'task_body' => 'Сверить остатки по складу и найти расхождения.',
        ]);
    }

    private function url(string $token, string $path = ''): string
    {
        return "/api/agent-hub/{$token}{$path}";
    }

    #[Test]
    public function discovery_returns_task_role_and_endpoints(): void
    {
        $this->getJson($this->url($this->topic->site_token))
            ->assertOk()
            ->assertJsonPath('topic.title', 'Сверка остатков')
            ->assertJsonPath('you', 'site')
            ->assertJsonPath('partner', 'erp')
            ->assertJsonPath('turn', 'site')
            ->assertJsonPath('your_turn', true)
            ->assertJsonStructure(['protocol', 'endpoints' => ['discovery', 'messages', 'post']]);

        $this->getJson($this->url($this->topic->erp_token))
            ->assertOk()
            ->assertJsonPath('you', 'erp')
            ->assertJsonPath('your_turn', false);
    }

    #[Test]
    public function unknown_token_returns_404(): void
    {
        $this->getJson($this->url('no-such-token'))->assertNotFound();
    }

    #[Test]
    public function message_in_turn_is_accepted_and_passes_turn(): void
    {
        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Начинаю сверку, вот список складов.',
            'payload' => ['warehouses' => ['tyumen-main']],
        ])
            ->assertCreated()
            ->assertJsonPath('message.seq', 1)
            ->assertJsonPath('message.author', 'site')
            ->assertJsonPath('turn', 'erp')
            ->assertJsonPath('status', 'in_progress');

        // Теперь ход у 1С — её сообщение проходит
        $this->postJson($this->url($this->topic->erp_token, '/messages'), [
            'body' => 'Принято, выгружаю регистр остатков.',
        ])
            ->assertCreated()
            ->assertJsonPath('message.seq', 2)
            ->assertJsonPath('turn', 'site');

        $this->assertSame(2, $this->topic->fresh()->last_seq);
    }

    #[Test]
    public function message_out_of_turn_is_rejected_with_409(): void
    {
        // Первый ход за сайтом — 1С пишет вне очереди
        $this->postJson($this->url($this->topic->erp_token, '/messages'), [
            'body' => 'Я первая!',
        ])
            ->assertStatus(409)
            ->assertJsonPath('turn', 'site')
            ->assertJsonPath('your_turn', false);

        $this->assertSame(0, $this->topic->messages()->count());
    }

    #[Test]
    public function retry_with_same_client_message_id_does_not_duplicate(): void
    {
        $payload = [
            'body' => 'Сообщение с ретраем.',
            'client_message_id' => 'msg-001',
        ];

        $this->postJson($this->url($this->topic->site_token, '/messages'), $payload)->assertCreated();

        // Ретрай после потери ответа: ход уже у 1С, но дубля не будет — вернётся принятое
        $this->postJson($this->url($this->topic->site_token, '/messages'), $payload)
            ->assertOk()
            ->assertJsonPath('repeated', true)
            ->assertJsonPath('message.seq', 1);

        $this->assertSame(1, $this->topic->messages()->count());
    }

    #[Test]
    public function resolution_without_partner_proposal_is_rejected(): void
    {
        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Закрываю задачу в одиночку.',
            'kind' => 'resolution',
        ])->assertStatus(422);

        $this->assertSame('open', $this->topic->fresh()->status);
    }

    #[Test]
    public function resolution_works_after_long_conversation(): void
    {
        // Регрессия: связь messages() отсортирована по seq ASC, и добавленный
        // orderByDesc оказывался второй сортировкой — проверка «последнее сообщение
        // партнёра» брала САМОЕ РАННЕЕ. На живом топике с историей переписки
        // resolution отбивался с 422, и закрыть топик было невозможно.
        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Первое сообщение: описываю расхождения.',
        ])->assertCreated();

        $this->postJson($this->url($this->topic->erp_token, '/messages'), [
            'body' => 'Проверил у себя, отвечаю.',
        ])->assertCreated();

        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Сверка сошлась. Предлагаю закрыть круг.',
            'kind' => 'proposal',
        ])->assertCreated();

        $this->postJson($this->url($this->topic->erp_token, '/messages'), [
            'body' => 'Подтверждаю итог, закрываем.',
            'kind' => 'resolution',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'resolved');

        $this->assertSame(AgentTopic::STATUS_RESOLVED, $this->topic->fresh()->status);
    }

    #[Test]
    public function resolution_survives_extra_messages_after_proposal(): void
    {
        // Предложение остаётся в силе, пока автор его не отозвал: реплика после
        // proposal (уточнение, досылка) не должна лишать партнёра возможности
        // подтвердить итог. Нашёл агент 1С на живом круге.
        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Сверка сошлась. Предлагаю закрыть круг.',
            'kind' => 'proposal',
        ])->assertCreated();

        $this->postJson($this->url($this->topic->erp_token, '/messages'), [
            'body' => 'Уточняющий вопрос перед подтверждением.',
        ])->assertCreated();

        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Отвечаю на уточнение, предложение в силе.',
        ])->assertCreated();

        $this->postJson($this->url($this->topic->erp_token, '/messages'), [
            'body' => 'Подтверждаю итог.',
            'kind' => 'resolution',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'resolved');

        $this->assertSame(AgentTopic::STATUS_RESOLVED, $this->topic->fresh()->status);
    }

    #[Test]
    public function proposal_resolution_handshake_resolves_topic(): void
    {
        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Расхождения устранены. Предлагаю закрыть: итог — 3 позиции исправлены.',
            'kind' => 'proposal',
        ])->assertCreated();

        $this->postJson($this->url($this->topic->erp_token, '/messages'), [
            'body' => 'Подтверждаю: данные в 1С совпадают с сайтом.',
            'kind' => 'resolution',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'resolved');

        $topic = $this->topic->fresh();
        $this->assertSame(AgentTopic::STATUS_RESOLVED, $topic->status);
        $this->assertSame('Подтверждаю: данные в 1С совпадают с сайтом.', $topic->resolution);

        // Завершённый топик больше не принимает сообщения
        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'А можно ещё?',
        ])->assertStatus(409);
    }

    #[Test]
    public function closed_topic_rejects_messages(): void
    {
        $this->topic->update(['status' => AgentTopic::STATUS_CLOSED]);

        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Стучусь в закрытую дверь.',
        ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'closed');
    }

    #[Test]
    public function messages_endpoint_filters_by_after_and_returns_payload(): void
    {
        $this->postJson($this->url($this->topic->site_token, '/messages'), [
            'body' => 'Первое.',
            'payload' => ['sku' => ['2209']],
        ]);
        $this->postJson($this->url($this->topic->erp_token, '/messages'), ['body' => 'Второе.']);

        $this->getJson($this->url($this->topic->site_token, '/messages?after=1'))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.seq', 2)
            ->assertJsonPath('your_turn', true)
            ->assertJsonPath('last_seq', 2);

        $this->getJson($this->url($this->topic->erp_token, '/messages'))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.payload.sku.0', '2209');
    }

    #[Test]
    public function validation_errors_are_in_russian(): void
    {
        $this->postJson($this->url($this->topic->site_token, '/messages'), [])
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0', 'Поле body обязательно: текст сообщения.');
    }

    #[Test]
    public function moderator_messages_are_visible_to_agents(): void
    {
        $this->topic->appendMessage(AgentTopicMessage::AUTHOR_MODERATOR, AgentTopicMessage::KIND_MESSAGE, 'Обратите внимание на склад Тюмень.');

        $this->getJson($this->url($this->topic->site_token, '/messages'))
            ->assertOk()
            ->assertJsonPath('messages.0.author', 'moderator')
            ->assertJsonPath('messages.0.body', 'Обратите внимание на склад Тюмень.');
    }
}
