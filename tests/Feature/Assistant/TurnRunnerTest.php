<?php

namespace Tests\Feature\Assistant;

use App\Jobs\Assistant\RunAssistantTurn;
use App\Models\ChatMessage;
use App\Models\ClientAssistantNote;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\Gateway\GatewayException;
use App\Services\Assistant\ThreadService;
use App\Services\Assistant\TurnRunner;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ход модели: запрос собран как надо, стрим склеен, ответ сохранён как есть,
 * история append-only, стоимость посчитана, ошибки шлюза не рушат тред.
 */
class TurnRunnerTest extends AssistantTestCase
{
    private function postAndRun(string $text, ?array $page = null): ChatMessage
    {
        Bus::fake([RunAssistantTurn::class]);

        $threads = app(ThreadService::class);
        $thread = \App\Models\ChatThread::query()->forUser($this->client)->open()->first() ?? $threads->open($this->client, $page);
        $placeholder = $threads->post($thread, $this->client, $text, [], $page);

        Bus::assertDispatched(RunAssistantTurn::class, fn (RunAssistantTurn $job) => $job->messageId === $placeholder->id);

        app(TurnRunner::class)->run($placeholder->id);

        return $placeholder->refresh();
    }

    #[Test]
    public function запрос_собран_с_mcp_коннектором_токеном_треда_и_бетами(): void
    {
        config()->set('app.url', 'https://pecado.ru');
        ClientAssistantNote::create(['user_id' => $this->client->id, 'content' => 'Заказывает по средам.']);

        $this->gateway->willAnswerText('Здравствуйте! Чем помочь?');

        $answer = $this->postAndRun('Привет', ['type' => 'product', 'id' => '42', 'title' => 'Комплект LE-13']);

        $request = $this->gateway->lastRequest();
        $this->assertSame('claude-opus-5', $request['model']);
        $this->assertSame('https://pecado.ru/mcp/client', $request['mcpServers'][0]['url']);
        $this->assertSame('pecado', $request['tools'][0]['mcp_server_name']);
        $this->assertContains('mcp-client-2025-11-20', $request['betas']);
        $this->assertContains('compact-2026-01-12', $request['betas']);
        $this->assertSame(['type' => 'adaptive'], $request['thinking']);
        $this->assertSame(['type' => 'ephemeral'], $request['cacheControl'], 'история кешируется до последнего блока');
        $this->assertSame('low', $request['outputConfig']['effort']);

        // Токен запроса — токен вида assistant, привязанный к треду.
        $token = \App\Models\ApiToken::query()->where('token', $request['mcpServers'][0]['authorization_token'])->first();
        $this->assertNotNull($token);
        $this->assertSame('assistant', $token->kind);
        $this->assertSame($token->id, $answer->thread->token_id);

        // Системная часть: инструкции с маркером кеша, потом блок клиента с заметкой и юрлицом.
        $this->assertSame(['type' => 'ephemeral'], $request['system'][0]['cache_control']);
        $this->assertStringContainsString('Ты заменяешь менеджера', $request['system'][0]['text']);
        // Список операций — в кешируемом блоке, вместо вызова client-catalog.
        $this->assertStringContainsString('- catalog.search (q*', $request['system'][0]['text']);
        $this->assertStringContainsString('- orders.create (', $request['system'][0]['text']);
        $this->assertStringContainsString('idempotency_key обязателен', $request['system'][0]['text']);
        $this->assertStringContainsString('client-catalog не вызывай', $request['system'][0]['text']);
        $this->assertStringContainsString('вакуумный стимулятор', $request['system'][0]['text']);
        $this->assertMatchesRegularExpression('/разделы кабинета открыты|Выключенные для клиента разделы/u', $request['system'][1]['text']);
        $this->assertStringContainsString('ООО Ромашка', $request['system'][1]['text']);
        $this->assertStringContainsString('Заказывает по средам.', $request['system'][1]['text']);

        // Контекст страницы — в ходе клиента, не в системном промпте.
        $this->assertStringContainsString('Страница: карточка товара «Комплект LE-13»', $request['messages'][0]['content'][0]['text']);
        $this->assertStringNotContainsString('Комплект LE-13', $request['system'][0]['text'].$request['system'][1]['text']);

        $this->assertSame('done', $answer->status);
        $this->assertSame('Здравствуйте! Чем помочь?', $answer->text);
    }

    #[Test]
    public function второй_ход_отправляет_первый_ответ_байт_в_байт_включая_блоки_мышления_и_mcp(): void
    {
        $blocks = [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'mcp_tool_use', 'id' => 'tu_1', 'name' => 'client-prices', 'server_name' => 'pecado', 'input' => ['identifiers' => ['LE-13']]],
            ['type' => 'mcp_tool_result', 'tool_use_id' => 'tu_1', 'is_error' => false, 'content' => [['type' => 'text', 'text' => '{"price": 1200}']]],
            ['type' => 'text', 'text' => 'LE-13 стоит 1 200 ₽.'],
        ];
        $this->gateway->willAnswer($blocks, ['input_tokens' => 1000, 'output_tokens' => 50, 'cache_read_input_tokens' => 4000, 'cache_creation_input_tokens' => 0]);
        $first = $this->postAndRun('Сколько стоит LE-13?');

        $this->assertSame($blocks, $first->content);
        $this->assertSame('LE-13 стоит 1 200 ₽.', $first->text);
        $this->assertSame(['client-prices'], ThreadService::messageToClient($first)['tools']);
        // 1000×5 + 50×25 + 4000×0.5 = 5000 + 1250 + 2000 = 8250 / 1e6
        $this->assertEqualsWithDelta(0.00825, (float) $first->cost, 0.00001);
        $this->assertSame(4000, $first->thread->refresh()->cache_read_tokens);

        $this->gateway->willAnswerText('Добавил.');
        $this->postAndRun('Добавь 10 штук в корзину');

        $messages = $this->gateway->lastRequest()['messages'];
        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame($blocks, $messages[1]['content'], 'ход модели повторяется без изменений');
        $this->assertSame('user', $messages[2]['role']);
    }

    #[Test]
    public function ошибка_баланса_помечает_ход_и_прячет_помощника_а_тред_остаётся_открытым(): void
    {
        $this->gateway->willFail(GatewayException::BILLING, 'credit balance is too low', 402);

        $answer = $this->postAndRun('Привет');

        $this->assertSame('failed', $answer->status);
        $this->assertSame('billing', $answer->error_code);
        $this->assertSame('Не удалось ответить, попробуйте позже.', $answer->text);
        $this->assertTrue($answer->thread->isOpen());
        $this->assertFalse(app(AssistantAvailability::class)->isAvailable());
    }

    #[Test]
    public function временная_ошибка_просит_повторить_и_не_прячет_помощника(): void
    {
        $this->gateway->willFail(GatewayException::OVERLOADED, 'overloaded', 529);

        $answer = $this->postAndRun('Привет');

        $this->assertSame('failed', $answer->status);
        $this->assertStringContainsString('через минуту', $answer->text);
        $this->assertTrue(app(AssistantAvailability::class)->isAvailable());

        // Неудавшийся ход в историю следующего запроса не попадает.
        $this->gateway->willAnswerText('Готово.');
        $this->postAndRun('Ещё раз');
        $roles = array_column($this->gateway->lastRequest()['messages'], 'role');
        $this->assertSame(['user', 'user'], $roles);
    }

    #[Test]
    public function отказ_модели_по_безопасности_даёт_нейтральный_текст(): void
    {
        $this->gateway->willAnswer([], [], 'refusal');

        $answer = $this->postAndRun('…');

        $this->assertSame('done', $answer->status);
        $this->assertSame('refusal', $answer->stop_reason);
        $this->assertStringContainsString('не могу помочь', $answer->text);
    }

    #[Test]
    public function блок_компакции_сохраняется_и_отмечает_тред(): void
    {
        $this->gateway->willAnswer([
            ['type' => 'compaction', 'content' => 'Клиент спрашивал цены на LE-13.'],
            ['type' => 'text', 'text' => 'Продолжим.'],
        ]);

        $answer = $this->postAndRun('Длинный разговор');

        $this->assertSame('compaction', $answer->content[0]['type']);
        $this->assertNotNull($answer->thread->refresh()->compacted_at);
    }
}
