<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\MeilisearchEmbedderManager;
use Illuminate\Console\Command;

/**
 * Аудит и точечный ремонт векторов (эмбеддингов) товаров в Meilisearch.
 *
 * Зачем: у новых товаров из 1С вектор может не сгенерироваться — если embedder
 * в индексе не настроен (индекс сбрасывался) или OpenRouter вернул ошибку
 * (402 Insufficient credits) при индексации. Такой документ остаётся в индексе
 * БЕЗ `_vectors`, и гибридный (семантический) поиск его не находит, хотя
 * keyword-поиск находит. Ничто в фоне такие документы не досчитывает.
 *
 * Что делает:
 *  1. Проверяет наличие embedder-а в индексе (при отсутствии/несовпадении
 *     dimensions — настраивает заново, идемпотентно).
 *  2. Находит документы без вектора.
 *  3. Показывает упавшие задачи индексации (диагностика 402/OpenRouter).
 *  4. С флагом --reindex-missing точечно переиндексирует только «безвекторные»
 *     товары (дёшево по кредитам OpenRouter — векторные не трогаются).
 */
class RepairMeilisearchEmbeddings extends Command
{
    protected $signature = 'search:repair-embeddings
                            {--dry-run : Только отчёт, без настройки embedder-а и переиндексации}
                            {--reindex-missing : Переиндексировать товары без вектора (тратит кредиты OpenRouter)}
                            {--limit= : Ограничить число просканированных документов}';

    protected $description = 'Аудит и точечный ремонт векторов товаров в Meilisearch';

    public function handle(MeilisearchEmbedderManager $manager): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $reindex = (bool) $this->option('reindex-missing');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! $manager->isHybridEnabled()) {
            $this->warn('Гибридный поиск выключен (SEARCH_HYBRID_ENABLED=false) — векторы не используются. Нечего чинить.');

            return self::SUCCESS;
        }

        if (! $manager->hasApiKey()) {
            $this->error('OPENROUTER_API_KEY не задан — Meilisearch не сможет генерировать эмбеддинги.');

            return self::FAILURE;
        }

        // ── Шаг 1: embedder настроен? ──────────────────────────────────
        if (! $this->ensureEmbedder($manager, $dryRun)) {
            return self::FAILURE;
        }

        // ── Шаг 2: аудит векторов ──────────────────────────────────────
        $this->newLine();
        $this->info('Аудит векторов документов индекса «'.$manager->indexName().'»...');

        $audit = $manager->documentIdsMissingVectors($limit);
        $missing = $audit['missing'];

        $this->line("  Всего в индексе: {$audit['index_total']}");
        $this->line("  Просканировано:  {$audit['total_scanned']}");
        $this->line('  Без вектора:     '.count($missing));

        if (! empty($missing)) {
            $preview = array_slice($missing, 0, 20);
            $this->line('  Примеры ID:      '.implode(', ', $preview).(count($missing) > 20 ? ', …' : ''));
        }

        // ── Шаг 3: упавшие задачи (диагностика причины) ────────────────
        $this->reportFailedTasks($manager);

        // ── Шаг 4: ремонт ──────────────────────────────────────────────
        if (empty($missing)) {
            $this->newLine();
            $this->info('✅ Все документы имеют вектор — ремонт не нужен.');

            return self::SUCCESS;
        }

        if (! $reindex) {
            $this->newLine();
            $this->comment('Найдены документы без вектора. Запустите с --reindex-missing для точечной переиндексации.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('  [dry-run] переиндексация '.count($missing).' товаров пропущена');

            return self::SUCCESS;
        }

        return $this->reindexMissing($missing);
    }

    /**
     * Убедиться, что embedder настроен в индексе; при необходимости — настроить.
     */
    private function ensureEmbedder(MeilisearchEmbedderManager $manager, bool $dryRun): bool
    {
        $current = $manager->getEmbedder();
        $expectedDimensions = $manager->configuredDimensions();

        $needsConfigure = $current === null
            || (isset($current['dimensions']) && (int) $current['dimensions'] !== $expectedDimensions);

        if (! $needsConfigure) {
            $this->info("✔ Embedder «{$manager->embedderName()}» настроен (dimensions={$expectedDimensions}).");

            return true;
        }

        if ($current === null) {
            $this->warn("✗ Embedder «{$manager->embedderName()}» в индексе не настроен.");
        } else {
            $this->warn("✗ Несовпадение dimensions: в индексе {$current['dimensions']}, ожидается {$expectedDimensions}.");
        }

        if ($dryRun) {
            $this->line('  [dry-run] настройка embedder-а пропущена');

            return true;
        }

        $this->line('  Настраиваю embedder...');
        $taskUid = $manager->configure();

        if ($taskUid === null) {
            $this->error('  ✗ Не удалось отправить настройку embedder-а в Meilisearch.');

            return false;
        }

        $result = $manager->waitForTask($taskUid);

        if ($result['status'] === 'failed') {
            $this->error("  ✗ Настройка embedder-а упала: {$result['error']}");

            return false;
        }

        $this->info('  ✅ Embedder настроен.');

        return true;
    }

    private function reportFailedTasks(MeilisearchEmbedderManager $manager): void
    {
        $failed = $manager->failedTasks();

        if (empty($failed)) {
            return;
        }

        $this->newLine();
        $this->warn('⚠ Есть упавшие задачи индексации ('.count($failed).') — вероятная причина отсутствия векторов:');

        foreach (array_slice($failed, 0, 5) as $task) {
            $message = $task['error']['message'] ?? 'без сообщения';
            $uid = $task['uid'] ?? '?';
            $this->line("  • task {$uid}: {$message}");
        }

        $this->line('  Если это «Insufficient credits» (402) — пополните баланс OpenRouter перед переиндексацией.');
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function reindexMissing(array $ids): int
    {
        $this->newLine();
        $this->info('Переиндексация '.count($ids).' товаров без вектора...');

        $reindexed = 0;

        collect($ids)->chunk(500)->each(function ($chunk) use (&$reindexed) {
            $products = Product::whereIn('id', $chunk->all())->get();
            $products->searchable();
            $reindexed += $products->count();
            $this->line("  → отправлено в индекс: {$reindexed}");
        });

        $this->newLine();
        $this->info("✅ Переиндексировано товаров: {$reindexed}. Meilisearch пересчитает векторы в фоне.");
        $this->line('   Проверьте результат повторным запуском без флагов (аудит).');

        return self::SUCCESS;
    }
}
