<?php

namespace App\Services\Assistant;

/**
 * Склеивает события стрима Messages API в сообщение целиком (wire-формат).
 *
 * Свой, а не из SDK, намеренно: воркер и тесты работают с массивами, и
 * накопитель не должен зависеть от классов SDK. Правила те же, что у всех
 * SDK: `message_start` даёт каркас, `content_block_start` открывает блок,
 * дельты дописывают текст, JSON аргументов, мысли и подписи, `message_delta`
 * приносит stop_reason и usage, `message_stop` закрывает.
 */
final class StreamAccumulator
{
    /** @var array<string, mixed>|null */
    private ?array $message = null;

    /** @var array<int, string> */
    private array $jsonBuffers = [];

    private bool $complete = false;

    /** Текст, накопленный с последнего flush — для частичного показа клиенту. */
    private string $pendingText = '';

    /**
     * @param  array<string, mixed>  $event
     */
    public function accumulate(array $event): void
    {
        $type = (string) ($event['type'] ?? '');

        if ($type === 'message_start') {
            $message = is_array($event['message'] ?? null) ? $event['message'] : [];
            $message['content'] = [];
            $this->message = $message;

            return;
        }

        if ($type === 'ping' || $this->message === null) {
            return;
        }

        switch ($type) {
            case 'content_block_start':
                $index = (int) ($event['index'] ?? count($this->message['content']));
                $block = is_array($event['content_block'] ?? null) ? $event['content_block'] : ['type' => 'text', 'text' => ''];

                if (($block['type'] ?? null) === 'text' && ! isset($block['text'])) {
                    $block['text'] = '';
                }

                $this->message['content'][$index] = $block;

                if (in_array($block['type'] ?? null, ['tool_use', 'mcp_tool_use', 'server_tool_use'], true)) {
                    $this->jsonBuffers[$index] = '';
                }

                break;

            case 'content_block_delta':
                $index = (int) ($event['index'] ?? 0);
                $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                $block = &$this->message['content'][$index];

                if (! is_array($block)) {
                    $block = ['type' => 'text', 'text' => ''];
                }

                switch ($delta['type'] ?? '') {
                    case 'text_delta':
                        $chunk = (string) ($delta['text'] ?? '');
                        $block['text'] = ($block['text'] ?? '').$chunk;
                        $this->pendingText .= $chunk;
                        break;
                    case 'input_json_delta':
                        $this->jsonBuffers[$index] = ($this->jsonBuffers[$index] ?? '').(string) ($delta['partial_json'] ?? '');
                        break;
                    case 'thinking_delta':
                        $block['thinking'] = ($block['thinking'] ?? '').(string) ($delta['thinking'] ?? '');
                        break;
                    case 'signature_delta':
                        $block['signature'] = (string) ($delta['signature'] ?? '');
                        break;
                    case 'citations_delta':
                        $block['citations'] = array_merge((array) ($block['citations'] ?? []), [$delta['citation'] ?? []]);
                        break;
                    case 'compaction_delta':
                        $block['content'] = ($block['content'] ?? '').(string) ($delta['content'] ?? '');
                        break;
                }

                unset($block);
                break;

            case 'content_block_stop':
                $index = (int) ($event['index'] ?? 0);

                if (isset($this->jsonBuffers[$index])) {
                    $decoded = json_decode($this->jsonBuffers[$index], true);

                    if (is_array($decoded)) {
                        $this->message['content'][$index]['input'] = $decoded;
                    } elseif (! isset($this->message['content'][$index]['input'])) {
                        $this->message['content'][$index]['input'] = new \stdClass;
                    }

                    unset($this->jsonBuffers[$index]);
                }

                break;

            case 'message_delta':
                $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];

                foreach (['stop_reason', 'stop_sequence', 'stop_details', 'container', 'context_management'] as $key) {
                    if (array_key_exists($key, $delta)) {
                        $this->message[$key] = $delta[$key];
                    }
                }

                if (is_array($event['usage'] ?? null)) {
                    $this->message['usage'] = array_merge((array) ($this->message['usage'] ?? []), $event['usage']);
                }

                break;

            case 'message_stop':
                $this->complete = true;
                break;
        }
    }

    /** Текст, накопленный с прошлого вызова; буфер очищается. */
    public function takePendingText(): string
    {
        $text = $this->pendingText;
        $this->pendingText = '';

        return $text;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function started(): bool
    {
        return $this->message !== null;
    }

    /**
     * Сообщение целиком: блоки по порядку индексов.
     *
     * @return array<string, mixed>
     */
    public function message(): array
    {
        $message = $this->message ?? ['content' => []];
        ksort($message['content']);
        $message['content'] = array_values($message['content']);

        return $message;
    }
}
