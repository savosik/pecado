<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Синхронизация поисковых индексов Meilisearch с базой данных.
 *
 * Выполняет upsert всех текущих записей, затем удаляет из индекса
 * документы, которых больше нет в БД (stale cleanup без downtime).
 */
class SyncSearchIndex extends Command
{
    protected $signature = 'search:sync
                            {--model= : Синхронизировать только указанную модель (products|categories|brands)}
                            {--skip-import : Пропустить импорт, только удалить устаревшие}
                            {--dry-run : Показать что будет сделано без изменений}';

    protected $description = 'Синхронизировать поисковые индексы Meilisearch с БД (upsert + stale cleanup)';

    private array $models = [
        'products'   => [Product::class,  'products'],
        'categories' => [Category::class, 'categories'],
        'brands'     => [Brand::class,    'brands'],
    ];

    public function handle(): int
    {
        $onlyModel = $this->option('model');
        $dryRun = $this->option('dry-run');
        $skipImport = $this->option('skip-import');

        if ($onlyModel && ! isset($this->models[$onlyModel])) {
            $this->error("Неизвестная модель: {$onlyModel}. Допустимые: " . implode(', ', array_keys($this->models)));

            return self::FAILURE;
        }

        $targets = $onlyModel
            ? [$onlyModel => $this->models[$onlyModel]]
            : $this->models;

        foreach ($targets as $key => [$modelClass, $indexName]) {
            $this->syncModel($modelClass, $indexName, $dryRun, $skipImport);
            $this->newLine();
        }

        $this->info('Синхронизация завершена.');

        return self::SUCCESS;
    }

    private function syncModel(string $modelClass, string $indexName, bool $dryRun, bool $skipImport): void
    {
        $this->info("=== {$indexName} ===");

        // Шаг 1: Импорт (upsert всех записей из БД в индекс)
        if (! $skipImport) {
            if ($dryRun) {
                $this->line('  [dry-run] scout:import пропущен');
            } else {
                $this->line('  Импорт...');
                $this->call('scout:import', ['model' => $modelClass]);
            }
        } else {
            $this->line('  Импорт пропущен (--skip-import)');
        }

        // Шаг 2: Поиск устаревших документов
        $this->line('  Поиск устаревших документов...');

        $indexedIds = $this->getAllIndexedIds($indexName);

        if ($indexedIds === null) {
            $this->error("  Не удалось получить документы из индекса {$indexName}.");

            return;
        }

        // Все ID из БД (без глобальных скоупов, чтобы не пропустить hidden-товары)
        $dbIds = $modelClass::withoutGlobalScopes()->pluck('id')->map(fn ($id) => (int) $id)->flip()->toArray();

        $staleIds = array_values(array_filter($indexedIds, fn ($id) => ! isset($dbIds[$id])));

        $inIndex = count($indexedIds);
        $inDb = count($dbIds);
        $staleCount = count($staleIds);

        $this->line("  В индексе: {$inIndex} | В БД: {$inDb} | Устаревших: {$staleCount}");

        if (empty($staleIds)) {
            $this->info('  Устаревших записей нет.');

            return;
        }

        $preview = implode(', ', array_slice($staleIds, 0, 10));
        $suffix = $staleCount > 10 ? '...' : '';

        if ($dryRun) {
            $this->warn("  [dry-run] Будет удалено {$staleCount} документов (ID: {$preview}{$suffix})");

            return;
        }

        // Шаг 3: Удаление устаревших документов из индекса
        $this->line("  Удаление {$staleCount} устаревших документов (ID: {$preview}{$suffix})...");
        $this->deleteFromIndex($indexName, $staleIds);
        $this->info("  Удалено {$staleCount} документов.");
    }

    /**
     * Получить все ID документов из индекса через API Meilisearch.
     * Возвращает null при ошибке соединения.
     *
     * @return int[]|null
     */
    private function getAllIndexedIds(string $indexName): ?array
    {
        $host = config('scout.meilisearch.host');
        $key = config('scout.meilisearch.key');

        $ids = [];
        $offset = 0;
        $limit = 1000;

        while (true) {
            $response = Http::withHeaders(['Authorization' => "Bearer {$key}"])
                ->get("{$host}/indexes/{$indexName}/documents", [
                    'fields' => 'id',
                    'limit' => $limit,
                    'offset' => $offset,
                ]);

            if (! $response->successful()) {
                $this->error("  Ошибка Meilisearch API: {$response->status()} — {$response->body()}");

                return null;
            }

            $results = $response->json('results', []);

            if (empty($results)) {
                break;
            }

            foreach ($results as $doc) {
                $ids[] = (int) $doc['id'];
            }

            $total = (int) $response->json('total', 0);
            $offset += $limit;

            if ($offset >= $total) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Пакетное удаление документов из индекса по ID.
     */
    private function deleteFromIndex(string $indexName, array $ids): void
    {
        $host = config('scout.meilisearch.host');
        $key = config('scout.meilisearch.key');

        foreach (array_chunk($ids, 1000) as $chunk) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type' => 'application/json',
            ])->post("{$host}/indexes/{$indexName}/documents/delete-batch", $chunk);

            if (! $response->successful()) {
                $this->error("  Ошибка удаления: {$response->status()} — {$response->body()}");
            }
        }
    }
}
