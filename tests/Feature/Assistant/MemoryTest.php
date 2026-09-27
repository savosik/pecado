<?php

namespace Tests\Feature\Assistant;

use App\Jobs\Assistant\RunAssistantTurn;
use App\Models\ApiToken;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Services\Assistant\AssistantTokenIssuer;
use App\Services\Assistant\ThreadService;
use App\Services\Assistant\TurnRunner;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

/**
 * Память между разговорами: «Новый разговор» закрывает прежний с резюме,
 * резюме попадает в промпт, а client-memory находит прошлые реплики.
 */
class MemoryTest extends AssistantTestCase
{
    private function threadWithDialog(string $question, string $answer): ChatThread
    {
        $thread = app(ThreadService::class)->open($this->client);
        ChatMessage::create(['thread_id' => $thread->id, 'role' => 'user', 'content' => [['type' => 'text', 'text' => $question]], 'text' => $question, 'status' => 'done']);
        ChatMessage::create(['thread_id' => $thread->id, 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => $answer]], 'text' => $answer, 'status' => 'done']);
        $thread->forceFill(['title' => $question, 'last_message_at' => now()])->save();

        return $thread;
    }

    #[Test]
    public function новый_разговор_закрывает_прежний_с_резюме_и_оно_попадает_в_промпт(): void
    {
        Bus::fake([RunAssistantTurn::class]);
        $old = $this->threadWithDialog('Собери корзину на 10 тысяч', 'Предлагаю LE-13 ×3 и 357012 ×5 на 9 800 ₽.');

        $this->gateway->willAnswerText("РЕЗЮМЕ:\nПредложил корзину: LE-13 ×3 и 357012 ×5 на 9 800 ₽, клиент не подтвердил.\n\nЗАМЕТКА:\nИнтересуется корзинами около 10 тысяч.");

        $fresh = $this->actingAs($this->client)
            ->postJson('/cabinet/assistant/threads', ['fresh' => true])
            ->assertOk()->json('thread.id');

        $this->assertNotSame($old->id, $fresh);
        $this->assertSame('closed', $old->refresh()->status);
        $this->assertStringContainsString('LE-13 ×3', $old->summary);

        // Следующий ход в новом треде видит резюме в блоке клиента.
        $this->gateway->willAnswerText('Ставлю в резерв LE-13 ×3 и 357012 ×5.');
        $placeholder = app(ThreadService::class)->post(ChatThread::find($fresh), $this->client, 'давай тогда в резерв то что ты предложил');
        app(TurnRunner::class)->run($placeholder->id);

        $system = $this->gateway->lastRequest()['system'][1]['text'];
        $this->assertStringContainsString('Последние разговоры', $system);
        $this->assertStringContainsString('LE-13 ×3', $system);
        $this->assertStringContainsString('Интересуется корзинами', $system);
        $this->assertStringContainsString('client-memory', $this->gateway->lastRequest()['system'][0]['text']);
    }

    #[Test]
    public function client_memory_отдаёт_резюме_и_находит_реплики_по_слову(): void
    {
        $past = $this->threadWithDialog('Найди стальные пробки', 'Металлических пробок с кристаллом 109 позиций, в наличии 717017-135.');
        $past->forceFill(['summary' => 'Искал стальные пробки, в наличии 717017-135.', 'status' => 'closed', 'last_message_at' => now()->subHour()])->save();

        $current = app(ThreadService::class)->open($this->client);
        $token = app(AssistantTokenIssuer::class)->forThread($current);

        $response = $this->postJson('/mcp/client', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'client-memory', 'arguments' => ['q' => '717017']],
        ], ['Authorization' => 'Bearer '.$token->token, 'Accept' => 'application/json, text/event-stream'])->assertOk();

        $data = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertSame($past->id, $data['recent'][0]['thread_id']);
        $this->assertStringContainsString('717017-135', $data['recent'][0]['summary']);
        $this->assertCount(1, $data['matches']);
        $this->assertSame('помощник', $data['matches'][0]['who']);
        $this->assertStringContainsString('717017-135', $data['matches'][0]['text']);
    }

    #[Test]
    public function незакрытый_тред_в_памяти_показывается_последним_ответом(): void
    {
        $open = $this->threadWithDialog('Что с заказом 15?', 'Заказ 15 собран, ждёт выдачи до 21:00.');
        $current = app(ThreadService::class)->open($this->client);
        $token = app(AssistantTokenIssuer::class)->forThread($current);

        $response = $this->postJson('/mcp/client', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'client-memory', 'arguments' => []],
        ], ['Authorization' => 'Bearer '.$token->token, 'Accept' => 'application/json, text/event-stream'])->assertOk();

        $data = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertSame($open->id, $data['recent'][0]['thread_id']);
        $this->assertStringContainsString('ждёт выдачи', $data['recent'][0]['summary']);
    }

    #[Test]
    public function личному_токену_память_помощника_недоступна(): void
    {
        $this->threadWithDialog('Вопрос', 'Ответ');
        $personal = ApiToken::create(['user_id' => $this->client->id, 'name' => 'Личный', 'is_active' => true]);

        $response = $this->postJson('/mcp/client', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'client-memory', 'arguments' => []],
        ], ['Authorization' => 'Bearer '.$personal->token, 'Accept' => 'application/json, text/event-stream'])->assertOk();

        $this->assertStringContainsString('только помощнику', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }
}
