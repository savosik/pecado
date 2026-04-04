<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Синхронизация словаря синонимов с Meilisearch.
 *
 * Читает resources/meilisearch/synonyms.php и отправляет
 * полный набор синонимов в указанные индексы.
 */
class SyncMeilisearchSynonyms extends Command
{
    protected $signature = 'meilisearch:sync-synonyms
                            {--index=* : Индексы для синхронизации (по умолчанию: products)}
                            {--reset : Удалить все синонимы из индексов}
                            {--dry-run : Показать синонимы без отправки}';

    protected $description = 'Синхронизировать словарь синонимов с Meilisearch';

    private const DEFAULT_INDEXES = ['products'];

    public function handle(): int
    {
        $host = config('scout.meilisearch.host');
        $key = config('scout.meilisearch.key');

        $indexes = $this->option('index') ?: self::DEFAULT_INDEXES;
        // Фильтруем пустые значения (artisan передаёт [] по умолчанию)
        $indexes = array_filter($indexes);
        if (empty($indexes)) {
            $indexes = self::DEFAULT_INDEXES;
        }

        if ($this->option('reset')) {
            return $this->resetSynonyms($host, $key, $indexes);
        }

        // Загрузка файла синонимов
        $synonymsFile = resource_path('meilisearch/synonyms.php');
        if (! file_exists($synonymsFile)) {
            $this->error("Файл синонимов не найден: {$synonymsFile}");
            return self::FAILURE;
        }

        $config = require $synonymsFile;
        $groups = $config['groups'] ?? [];
        $oneWay = $config['one_way'] ?? [];

        // Развернуть группы в формат Meilisearch
        $synonyms = $this->expandGroups($groups);

        // Добавить однонаправленные синонимы
        foreach ($oneWay as $key => $values) {
            $normalizedKey = mb_strtolower(trim($key));
            if (isset($synonyms[$normalizedKey])) {
                // Объединяем с существующими
                $synonyms[$normalizedKey] = array_values(array_unique(
                    array_merge($synonyms[$normalizedKey], $values)
                ));
            } else {
                $synonyms[$normalizedKey] = $values;
            }
        }

        $this->info("📚 Загружено синонимов:");
        $this->info("   Групп (двусторонние): " . count($groups));
        $this->info("   Однонаправленных: " . count($oneWay));
        $this->info("   Итого записей для Meilisearch: " . count($synonyms));

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info("Пример (первые 10 записей):");
            $i = 0;
            foreach ($synonyms as $word => $syns) {
                if ($i >= 10) break;
                $this->line("   {$word} → " . implode(', ', $syns));
                $i++;
            }
            $this->newLine();
            $this->warn("Режим dry-run: синонимы НЕ отправлены в Meilisearch.");
            return self::SUCCESS;
        }

        foreach ($indexes as $indexName) {
            $this->syncToIndex($host, config('scout.meilisearch.key'), $indexName, $synonyms);
        }

        $this->newLine();
        $this->info("✅ Синонимы синхронизированы.");

        return self::SUCCESS;
    }

    /**
     * Развернуть группы синонимов в формат Meilisearch.
     *
     * Из группы ['a', 'b', 'c'] генерируется:
     *   'a' => ['b', 'c'],
     *   'b' => ['a', 'c'],
     *   'c' => ['a', 'b'],
     */
    private function expandGroups(array $groups): array
    {
        $synonyms = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            // Нормализация: trim + lowercase
            $group = array_map(fn ($w) => mb_strtolower(trim($w)), $group);
            $group = array_unique($group);

            foreach ($group as $word) {
                $others = array_values(array_filter($group, fn ($w) => $w !== $word));
                if (empty($others)) continue;

                if (isset($synonyms[$word])) {
                    $synonyms[$word] = array_values(array_unique(
                        array_merge($synonyms[$word], $others)
                    ));
                } else {
                    $synonyms[$word] = $others;
                }
            }
        }

        return $synonyms;
    }

    private function syncToIndex(string $host, string $key, string $indexName, array $synonyms): void
    {
        $this->info("📦 Индекс: {$indexName}");

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
        ])->put("{$host}/indexes/{$indexName}/settings/synonyms", $synonyms);

        if ($response->successful()) {
            $taskUid = $response->json('taskUid');
            $this->info("   → Задача создана: taskUid={$taskUid}");
            $this->waitForTask($host, $key, $taskUid);
        } else {
            $this->error("   ✗ Ошибка: {$response->status()} — {$response->body()}");
        }
    }

    private function waitForTask(string $host, string $key, int $taskUid): void
    {
        $this->info("   ⏳ Ожидание...");

        $maxAttempts = 60;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
            ])->get("{$host}/tasks/{$taskUid}");

            if (! $response->successful()) {
                $this->error("   ✗ Не удалось проверить задачу: {$response->status()}");
                return;
            }

            $status = $response->json('status');

            if ($status === 'succeeded') {
                $this->info("   ✅ Готово");
                return;
            }

            if ($status === 'failed') {
                $error = $response->json('error.message', 'Неизвестная ошибка');
                $this->error("   ✗ Ошибка: {$error}");
                return;
            }

            $attempt++;
            sleep(2);
        }

        $this->warn("   ⚠ Таймаут. Задача ещё выполняется.");
    }

    private function resetSynonyms(string $host, string $key, array $indexes): int
    {
        $this->info('Удаление синонимов...');

        foreach ($indexes as $indexName) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->put("{$host}/indexes/{$indexName}/settings/synonyms", (object) []);

            if ($response->successful()) {
                $this->info("   ✅ {$indexName}: синонимы удалены");
            } else {
                $this->error("   ✗ {$indexName}: {$response->status()} — {$response->body()}");
            }
        }

        return self::SUCCESS;
    }
}
