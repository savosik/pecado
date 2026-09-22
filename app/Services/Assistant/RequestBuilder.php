<?php

namespace App\Services\Assistant;

use App\Models\ApiToken;
use App\Models\ChatMessage;
use App\Models\ChatThread;

/**
 * Собирает запрос к Messages API из треда: системные блоки, история
 * append-only, MCP-коннектор с токеном треда, беты, мышление и усилие.
 *
 * Ключи — именованные аргументы `createStream` SDK; содержимое блоков — в
 * wire-формате, как хранится в chat_messages. Порядок неизменной части
 * (инструменты → системный промпт → блок клиента) — ради кеша промпта.
 */
final class RequestBuilder
{
    public function __construct(private readonly SystemPrompt $system) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ChatThread $thread, ApiToken $token): array
    {
        $betas = config('assistant.betas', []);

        $request = [
            'model' => (string) config('assistant.model'),
            'maxTokens' => (int) config('assistant.max_tokens', 16000),
            'system' => $this->system->blocks($thread),
            'messages' => $this->history($thread),
            'mcpServers' => [[
                'type' => 'url',
                'name' => (string) config('assistant.mcp.name', 'pecado'),
                'url' => self::mcpUrl(),
                'authorization_token' => $token->token,
            ]],
            'tools' => [[
                'type' => 'mcp_toolset',
                'mcp_server_name' => (string) config('assistant.mcp.name', 'pecado'),
            ]],
            // Маркер кеша на последний блок истории: без него кешируется только
            // системная часть, а вся переписка с результатами инструментов
            // (каталог, акции — десятки тысяч токенов) оплачивается каждым ходом
            // по полной цене. С маркером следующий ход читает её за десятую часть.
            'cacheControl' => ['type' => 'ephemeral'],
            'thinking' => ['type' => 'adaptive'],
            'outputConfig' => ['effort' => (string) config('assistant.effort', 'low')],
            'contextManagement' => ['edits' => [['type' => 'compact_20260112']]],
            'fallbacks' => 'default',
            'metadata' => ['user_id' => 'client:'.$thread->user_id],
            'betas' => array_values(array_filter([
                $betas['mcp'] ?? null,
                $betas['compaction'] ?? null,
                $betas['fallback'] ?? null,
            ])),
        ];

        if ($this->hasContainerFiles($thread)) {
            // Таблицы клиента читает песочница Anthropic: файл уже в Files API,
            // модели нужен инструмент выполнения кода, который его откроет.
            $request['tools'][] = ['type' => 'code_execution_20260521', 'name' => 'code_execution'];
            $request['betas'][] = 'code-execution-2025-08-25';
        }

        return $request;
    }

    public static function mcpUrl(): string
    {
        $configured = (string) config('assistant.mcp.url', '');

        return $configured !== '' ? $configured : rtrim((string) config('app.url'), '/').'/mcp/client';
    }

    /**
     * История треда как есть. Ходы со статусом failed/pending пропускаются —
     * это не ответы модели, а наши отметки. Если ходов больше лимита, история
     * начинается с последнего блока компакции, до него — отбрасывается.
     *
     * @return list<array{role: string, content: mixed}>
     */
    private function history(ChatThread $thread): array
    {
        $messages = $thread->messages()
            ->whereIn('status', [ChatMessage::STATUS_DONE])
            ->get(['id', 'role', 'content']);

        $limit = max(10, (int) config('assistant.threads.history_limit', 200));

        if ($messages->count() > $limit) {
            $lastCompaction = null;

            foreach ($messages as $index => $message) {
                foreach ((array) $message->content as $block) {
                    if (($block['type'] ?? null) === 'compaction') {
                        $lastCompaction = $index;
                    }
                }
            }

            if ($lastCompaction !== null) {
                $messages = $messages->slice($lastCompaction)->values();
            }
        }

        $attachments = $thread->attachments()->get()->keyBy('id');
        $history = [];

        foreach ($messages as $message) {
            $content = $this->expand((array) $message->content, $attachments);

            if ($content === []) {
                continue;
            }

            $history[] = ['role' => $message->role, 'content' => $content];
        }

        return $history;
    }

    /**
     * Заглушки вложений → настоящие блоки. Развёртка детерминирована: тот же
     * файл даёт те же байты при каждом повторе истории.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  \Illuminate\Support\Collection<int, \App\Models\ChatAttachment>  $attachments
     * @return list<array<string, mixed>>
     */
    private function expand(array $content, \Illuminate\Support\Collection $attachments): array
    {
        $expanded = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) !== 'x-attachment') {
                $expanded[] = $block;

                continue;
            }

            $attachment = $attachments->get((int) ($block['id'] ?? 0));

            if ($attachment !== null) {
                $expanded[] = AttachmentService::block($attachment);
            }
        }

        return $expanded;
    }

    private function hasContainerFiles(ChatThread $thread): bool
    {
        return $thread->attachments()
            ->whereNotNull('anthropic_file_id')
            ->whereIn('kind', [\App\Models\ChatAttachment::KIND_TABLE, \App\Models\ChatAttachment::KIND_TEXT])
            ->exists();
    }
}
