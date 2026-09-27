<?php

namespace Tests\Support\Assistant;

use App\Services\Assistant\Gateway\AssistantGateway;
use App\Services\Assistant\Gateway\GatewayException;

/**
 * Фейк шлюза к Anthropic для тестов: заранее заданные ответы, запись запросов.
 *
 * Ответ задаётся как сообщение целиком (wire-формат) — фейк сам режет его на
 * события стрима, как это сделал бы SSE: message_start, блоки, message_delta
 * с usage, message_stop. Так воркер тестируется тем же кодом, что и в бою.
 */
final class FakeGateway implements AssistantGateway
{
    /** @var list<array<string, mixed>|GatewayException> */
    private array $queue = [];

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var list<array{path: string, filename: string, mime: string}> */
    public array $uploads = [];

    private int $fileCounter = 0;

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $usage
     */
    public function willAnswer(array $content, array $usage = [], string $stopReason = 'end_turn', string $model = 'claude-opus-5'): self
    {
        $this->queue[] = [
            'id' => 'msg_fake_'.(count($this->queue) + 1),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => $usage + [
                'input_tokens' => 120,
                'output_tokens' => 40,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ],
        ];

        return $this;
    }

    public function willAnswerText(string $text, array $usage = []): self
    {
        return $this->willAnswer([['type' => 'text', 'text' => $text]], $usage);
    }

    public function willFail(string $kind, string $message = 'ошибка', ?int $status = null): self
    {
        $this->queue[] = new GatewayException($kind, $message, $status);

        return $this;
    }

    public function stream(array $request): iterable
    {
        $message = $this->take($request);

        yield ['type' => 'message_start', 'message' => ['content' => [], 'usage' => ['input_tokens' => $message['usage']['input_tokens'], 'output_tokens' => 0]] + $message];

        foreach ($message['content'] as $index => $block) {
            if (($block['type'] ?? null) === 'text') {
                yield ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']];
                // Текст режем на две дельты: воркер должен склеивать частичный текст.
                $text = (string) $block['text'];
                $half = (int) ceil(mb_strlen($text) / 2);
                yield ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => mb_substr($text, 0, $half)]];
                yield ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => mb_substr($text, $half)]];
            } elseif (($block['type'] ?? null) === 'thinking') {
                yield ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'thinking', 'thinking' => '']];
                yield ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'signature_delta', 'signature' => (string) ($block['signature'] ?? 'sig')]];
            } else {
                // Вызовы и результаты MCP, компакция — приходят блоком целиком.
                yield ['type' => 'content_block_start', 'index' => $index, 'content_block' => $block];
            }

            yield ['type' => 'content_block_stop', 'index' => $index];
        }

        yield ['type' => 'message_delta', 'delta' => ['stop_reason' => $message['stop_reason'], 'stop_sequence' => null], 'usage' => $message['usage']];
        yield ['type' => 'message_stop'];
    }

    public function create(array $request): array
    {
        return $this->take($request);
    }

    public function uploadFile(string $path, string $filename, string $mime): string
    {
        $this->uploads[] = ['path' => $path, 'filename' => $filename, 'mime' => $mime];

        return 'file_fake_'.(++$this->fileCounter);
    }

    /** Последний отправленный запрос — что именно ушло бы в API. */
    public function lastRequest(): array
    {
        return $this->requests[array_key_last($this->requests)] ?? [];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function take(array $request): array
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            $next = new GatewayException(GatewayException::UNKNOWN, 'FakeGateway: ответ не задан (willAnswer/willFail).');
        }

        if ($next instanceof GatewayException) {
            throw $next;
        }

        return $next;
    }
}
