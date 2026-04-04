<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Настройка embedders в Meilisearch для гибридного (векторного) поиска.
 *
 * Эта команда конфигурирует REST embedder через Meilisearch API напрямую,
 * т.к. scout:sync-index-settings может не поддерживать вложенные
 * request/response шаблоны для REST embedder-а.
 */
class ConfigureMeilisearchEmbedders extends Command
{
    protected $signature = 'meilisearch:configure-embedders
                            {--reset : Удалить embedders из индексов}';

    protected $description = 'Настроить embedders (векторный поиск) в Meilisearch через REST API';

    public function handle(): int
    {
        $host = config('scout.meilisearch.host');
        $key = config('scout.meilisearch.key');
        $searchConfig = config('search');

        if (! $searchConfig['hybrid']['enabled']) {
            $this->warn('Гибридный поиск выключен (SEARCH_HYBRID_ENABLED=false). Включите и запустите снова.');
            return self::FAILURE;
        }

        $embedderName = $searchConfig['hybrid']['embedder'];
        $embedderConfig = $searchConfig['embedder'];

        if (empty($embedderConfig['api_key'])) {
            $this->error('OPENROUTER_API_KEY не задан в .env');
            return self::FAILURE;
        }

        // Индексы, для которых настраиваем embedders
        $indexes = [
            'products' => "Товар: {{doc.name}}. Бренд: {{doc.brand}}. Категория: {{doc.category}}. {{doc.description}}",
        ];

        if ($this->option('reset')) {
            return $this->resetEmbedders($host, $key, $indexes);
        }

        $this->info('Настройка embedders в Meilisearch...');
        $this->newLine();

        foreach ($indexes as $indexName => $documentTemplate) {
            $this->configureEmbedder(
                $host,
                $key,
                $indexName,
                $embedderName,
                $embedderConfig,
                $documentTemplate
            );
        }

        $this->newLine();
        $this->info('✅ Embedders настроены. Meilisearch начнёт генерацию embeddings в фоне.');
        $this->info('Отслеживайте прогресс: curl -s ' . $host . '/tasks?types=settingsUpdate -H "Authorization: Bearer ' . $key . '" | jq');

        return self::SUCCESS;
    }

    private function configureEmbedder(
        string $host,
        string $key,
        string $indexName,
        string $embedderName,
        array $embedderConfig,
        string $documentTemplate
    ): void {
        $this->info("📦 Индекс: {$indexName}");

        $payload = [
            $embedderName => [
                'source' => 'rest',
                'url' => $embedderConfig['url'],
                'apiKey' => $embedderConfig['api_key'],
                'dimensions' => $embedderConfig['dimensions'],
                'documentTemplate' => $documentTemplate,
                'documentTemplateMaxBytes' => $embedderConfig['document_template_max_bytes'],
                'request' => [
                    'model' => $embedderConfig['model'],
                    'input' => ['{{text}}', '{{..}}'],
                ],
                'response' => [
                    'data' => [
                        ['embedding' => '{{embedding}}'],
                        '{{..}}',
                    ],
                ],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
        ])->patch("{$host}/indexes/{$indexName}/settings/embedders", $payload);

        if ($response->successful()) {
            $taskUid = $response->json('taskUid');
            $this->info("  → Задача создана: taskUid={$taskUid}");
            $this->waitForTask($host, $key, $taskUid);
        } else {
            $this->error("  ✗ Ошибка: {$response->status()} — {$response->body()}");
        }
    }

    private function waitForTask(string $host, string $key, int $taskUid): void
    {
        $this->info("  ⏳ Ожидание завершения задачи...");

        $maxAttempts = 120; // 10 минут (120 * 5s)
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
            ])->get("{$host}/tasks/{$taskUid}");

            if (! $response->successful()) {
                $this->error("  ✗ Не удалось получить статус задачи: {$response->status()}");
                return;
            }

            $task = $response->json();
            $status = $task['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                $this->info("  ✅ Задача завершена успешно");
                return;
            }

            if ($status === 'failed') {
                $error = $task['error']['message'] ?? 'Неизвестная ошибка';
                $this->error("  ✗ Задача завершена с ошибкой: {$error}");
                return;
            }

            // enqueued или processing — ждём
            $attempt++;
            sleep(5);
        }

        $this->warn("  ⚠ Превышен таймаут ожидания. Задача ещё выполняется в фоне.");
    }

    private function resetEmbedders(string $host, string $key, array $indexes): int
    {
        $this->info('Удаление embedders из индексов...');

        foreach (array_keys($indexes) as $indexName) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->delete("{$host}/indexes/{$indexName}/settings/embedders");

            if ($response->successful()) {
                $this->info("  ✅ {$indexName}: embedders удалены");
            } else {
                $this->error("  ✗ {$indexName}: {$response->status()} — {$response->body()}");
            }
        }

        return self::SUCCESS;
    }
}
