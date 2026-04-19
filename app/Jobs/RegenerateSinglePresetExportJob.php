<?php

namespace App\Jobs;

use App\Models\ProductExport;
use App\Services\ProductExport\Presets\PresetRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Фоновая регенерация кэша одного конкретного экспорта.
 *
 * Стратегия:
 * - Диспатчится из контроллера скачивания, когда кеш устарел
 * - WithoutOverlapping гарантирует, что для одного export_id одновременно работает только 1 job
 * - Выполняется в очереди 'exports' с 1 воркером → сериализована генерация
 * - Если 100 пользователей запросят файлы в одно время — все получат текущий (стаалый) кеш,
 *   а фоновая регенерация пройдёт последовательно без OOM
 */
class RegenerateSinglePresetExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 минут на один экспорт

    public int $tries = 1;

    public function __construct(
        public int $exportId,
    ) {
        $this->onConnection('redis')->onQueue('exports');
    }

    /**
     * Middleware: не запускать дубликаты для того же экспорта.
     * Если job для этого export_id уже в очереди/выполняется — новый пропускается.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("preset-export-{$this->exportId}"))
                ->dontRelease()    // не ставить повторно в очередь, просто пропустить
                ->expireAfter(600), // лок истекает через 10 минут (safety)
        ];
    }

    public function handle(PresetRegistry $presetRegistry): void
    {
        $export = ProductExport::find($this->exportId);

        if (! $export || ! $export->is_active || ! $export->preset) {
            Log::info("[PresetExports] Экспорт #{$this->exportId} не найден или неактивен, пропуск.");

            return;
        }

        $preset = $presetRegistry->resolve($export->preset);
        if (! $preset) {
            Log::warning("[PresetExports] Неизвестный пресет: {$export->preset} (export #{$export->id})");

            return;
        }

        try {
            $cacheDir = dirname($export->getCacheFilePath());
            if (! is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            $filePath = $export->getCacheFilePath();

            // Пишем во временный файл, затем атомарно заменяем — чтобы
            // параллельные запросы не читали наполовину записанный файл
            $tmpPath = $filePath.'.tmp.'.getmypid();
            $stream = fopen($tmpPath, 'w');
            $preset->writeToStream($stream, $export);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Атомарная замена
            rename($tmpPath, $filePath);

            $export->update(['cached_at' => now()]);

            $size = filesize($filePath);
            Log::info("[PresetExports] Кэш обновлён: {$export->preset} (export #{$export->id}, {$size} bytes)");
        } catch (\Throwable $e) {
            // Чистим за собой
            if (isset($tmpPath) && file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
            Log::error("[PresetExports] Ошибка генерации {$export->preset} (export #{$export->id}): {$e->getMessage()}");
            throw $e;
        }
    }
}
