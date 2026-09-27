<?php

namespace Tests\Feature\Assistant;

use App\Models\ChatMessage;
use App\Models\ClientAssistantNote;
use App\Services\Assistant\AssistantTokenIssuer;
use App\Services\Assistant\ThreadCloser;
use App\Services\Assistant\ThreadService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Память клиента: закрытие по бездействию, резюме треда, заметка.
 */
class ThreadCloserTest extends AssistantTestCase
{
    #[Test]
    public function неактивный_тред_закрывается_с_резюме_и_заметкой_а_свежий_остаётся(): void
    {
        config()->set('assistant.threads.idle_hours', 24);
        $threads = app(ThreadService::class);

        $old = $threads->open($this->client);
        $token = app(AssistantTokenIssuer::class)->forThread($old);
        ChatMessage::create(['thread_id' => $old->id, 'role' => 'user', 'content' => [['type' => 'text', 'text' => 'Сколько стоит LE-13?']], 'text' => 'Сколько стоит LE-13?', 'status' => 'done']);
        ChatMessage::create(['thread_id' => $old->id, 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => '1 200 ₽']], 'text' => '1 200 ₽', 'status' => 'done']);
        $old->forceFill(['last_message_at' => now()->subHours(30)])->save();

        $fresh = $threads->open($this->client);
        $fresh->forceFill(['last_message_at' => now()->subHours(2)])->save();

        ClientAssistantNote::create(['user_id' => $this->client->id, 'content' => 'Заказывает по средам.', 'version' => 1]);

        $this->gateway->willAnswerText("РЕЗЮМЕ:\nКлиент узнал цену LE-13.\n\nЗАМЕТКА:\nЗаказывает по средам. Интересуется LE-13.");

        $this->artisan('assistant:close-idle')->assertSuccessful();

        $this->assertSame('closed', $old->refresh()->status);
        $this->assertSame('open', $fresh->refresh()->status);
        $this->assertFalse($token->refresh()->is_active);
        $this->assertSame('Клиент узнал цену LE-13.', $old->summary);

        $note = ClientAssistantNote::query()->where('user_id', $this->client->id)->first();
        $this->assertSame('Заказывает по средам. Интересуется LE-13.', $note->content);
        $this->assertSame(2, $note->version);
        $this->assertSame('model', $note->updated_by);

        $request = $this->gateway->lastRequest();
        $this->assertStringContainsString('Сколько стоит LE-13?', $request['messages'][0]['content']);
        $this->assertArrayNotHasKey('mcpServers', $request, 'память пишется без инструментов');
        $this->assertStringContainsString('не записывай ни как «открытые вопросы»', $request['messages'][0]['content'], 'неотвеченные предложения помощника — не обязательства');
        $this->assertStringContainsString('ограничения сайта', $request['messages'][0]['content'], 'заметка о клиенте, а не о сайте');
    }

    #[Test]
    public function сбой_модели_не_мешает_закрытию(): void
    {
        $thread = app(ThreadService::class)->open($this->client);
        ChatMessage::create(['thread_id' => $thread->id, 'role' => 'user', 'content' => [['type' => 'text', 'text' => 'Привет']], 'text' => 'Привет', 'status' => 'done']);
        $thread->forceFill(['last_message_at' => now()->subDays(2)])->save();

        $this->gateway->willFail(\App\Services\Assistant\Gateway\GatewayException::OVERLOADED, 'overloaded', 529);

        app(ThreadCloser::class)->closeIdle();

        $this->assertSame('closed', $thread->refresh()->status);
        $this->assertNull($thread->summary);
    }

    #[Test]
    public function пустой_тред_закрывается_без_запроса_к_модели(): void
    {
        $thread = app(ThreadService::class)->open($this->client);
        $thread->forceFill(['last_message_at' => now()->subDays(2)])->save();

        app(ThreadCloser::class)->closeIdle();

        $this->assertSame('closed', $thread->refresh()->status);
        $this->assertSame([], $this->gateway->requests);
    }

    #[Test]
    public function ответ_без_разметки_становится_резюме_а_заметка_не_трогается(): void
    {
        [$summary, $note] = ThreadCloser::parse('Просто текст без разделов.');

        $this->assertSame('Просто текст без разделов.', $summary);
        $this->assertSame('', $note);
    }
}
