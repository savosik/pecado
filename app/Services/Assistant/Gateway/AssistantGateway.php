<?php

namespace App\Services\Assistant\Gateway;

/**
 * Шлюз к Messages API: единственное место, где помощник говорит с Anthropic.
 *
 * Запрос и ответ — массивы в терминах SDK (именованные аргументы
 * `createStream`: model, maxTokens, messages, system, mcpServers, tools,
 * betas…), события стрима и сообщения — в wire-формате API (snake_case), как
 * их хранит `chat_messages.content`. Тесты подменяют шлюз фейком, реальный
 * API в CI не вызывается.
 */
interface AssistantGateway
{
    /**
     * Потоковый запрос: генератор событий SSE в wire-формате
     * (`message_start`, `content_block_delta`, … `message_stop`).
     *
     * @param  array<string, mixed>  $request
     * @return iterable<array<string, mixed>>
     *
     * @throws GatewayException
     */
    public function stream(array $request): iterable;

    /**
     * Обычный запрос: сообщение целиком в wire-формате (для проб и коротких
     * служебных вызовов вроде резюме треда).
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    public function create(array $request): array;

    /**
     * Загрузка файла в Files API: возвращает идентификатор `file_…`.
     *
     * @throws GatewayException
     */
    public function uploadFile(string $path, string $filename, string $mime): string;
}
