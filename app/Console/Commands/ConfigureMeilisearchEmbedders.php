<?php

namespace App\Console\Commands;

use App\Services\Search\MeilisearchEmbedderManager;
use Illuminate\Console\Command;

/**
 * Настройка embedders в Meilisearch для гибридного (векторного) поиска.
 *
 * Конфигурирует REST embedder через Meilisearch API напрямую (см.
 * MeilisearchEmbedderManager). Команду достаточно запустить один раз после
 * поднятия/сброса индекса; для точечного досчёта векторов у новых товаров
 * используйте `search:repair-embeddings`.
 */
class ConfigureMeilisearchEmbedders extends Command
{
    protected $signature = 'meilisearch:configure-embedders
                            {--reset : Удалить embedders из индексов}';

    protected $description = 'Настроить embedders (векторный поиск) в Meilisearch через REST API';

    public function handle(MeilisearchEmbedderManager $manager): int
    {
        if (! $manager->isHybridEnabled()) {
            $this->warn('Гибридный поиск выключен (SEARCH_HYBRID_ENABLED=false). Включите и запустите снова.');

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            return $this->resetEmbedder($manager);
        }

        if (! $manager->hasApiKey()) {
            $this->error('OPENROUTER_API_KEY не задан в .env');

            return self::FAILURE;
        }

        $this->info('Настройка embedders в Meilisearch...');
        $this->newLine();
        $this->info("📦 Индекс: {$manager->indexName()}");

        $taskUid = $manager->configure();

        if ($taskUid === null) {
            $this->error('  ✗ Не удалось отправить настройку embedder-а в Meilisearch.');

            return self::FAILURE;
        }

        $this->info("  → Задача создана: taskUid={$taskUid}");
        $this->info('  ⏳ Ожидание завершения задачи...');

        $result = $manager->waitForTask($taskUid);

        if ($result['status'] === 'succeeded') {
            $this->info('  ✅ Задача завершена успешно');
        } elseif ($result['status'] === 'failed') {
            $this->error("  ✗ Задача завершена с ошибкой: {$result['error']}");

            return self::FAILURE;
        } else {
            $this->warn('  ⚠ Задача ещё выполняется в фоне (таймаут ожидания или неизвестный статус).');
        }

        $this->newLine();
        $this->info('✅ Embedders настроены. Meilisearch начнёт генерацию embeddings в фоне.');

        return self::SUCCESS;
    }

    private function resetEmbedder(MeilisearchEmbedderManager $manager): int
    {
        $this->info('Удаление embedders из индекса...');

        $taskUid = $manager->reset();

        if ($taskUid === null) {
            $this->error("  ✗ {$manager->indexName()}: не удалось удалить embedders");

            return self::FAILURE;
        }

        $this->info("  ✅ {$manager->indexName()}: embedders удалены (taskUid={$taskUid})");

        return self::SUCCESS;
    }
}
