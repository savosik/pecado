<?php

namespace App\Services\ProductExport;

use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Services\ProductExport\Presets\AbstractPreset;
use App\Services\ProductExport\Presets\PresetInterface;
use App\Services\ProductExport\Presets\PresetRegistry;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Сервис генерации файла стандартной пресетной выгрузки.
 *
 * Извлечён из ProductExportDownloadController, чтобы:
 * - вызываться из GenerateProductExportJob (асинхронно из очереди);
 * - вести историю запусков в product_export_runs;
 * - надёжно очищать tmp-файл и поток через try/finally.
 *
 * Атомарность: пишем в `{file}.tmp.<pid>` и rename'имся, чтобы параллельные
 * читатели не получили наполовину записанный файл.
 */
class ProductExportGenerator
{
    public function __construct(
        protected PresetRegistry $presetRegistry,
    ) {}

    /**
     * Сгенерировать файл выгрузки и обновить связанные модели.
     * Бросает RuntimeException, если пресет не найден или поток не открылся —
     * вызывающая сторона (Job) поймает и переведёт run в STATUS_FAILED.
     */
    public function generate(ProductExport $export): ProductExportRun
    {
        $preset = $this->resolvePreset($export);

        $run = ProductExportRun::create([
            'product_export_id' => $export->id,
            'status' => ProductExportRun::STATUS_GENERATING,
            'started_at' => now(),
        ]);

        $export->update([
            'status' => ProductExport::STATUS_GENERATING,
            'last_run_id' => $run->id,
        ]);

        $startedAt = microtime(true);

        try {
            $bytes = $this->writeAtomic($export, $preset);
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            $run->update([
                'status' => ProductExportRun::STATUS_READY,
                'finished_at' => now(),
                'duration_ms' => $duration,
                'bytes' => $bytes,
                'rows_count' => $preset instanceof AbstractPreset ? $preset->getRowsProcessed() : null,
            ]);

            $export->update([
                'status' => ProductExport::STATUS_READY,
                'cached_at' => now(),
            ]);

            return $run->fresh();
        } catch (Throwable $e) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            $run->update([
                'status' => ProductExportRun::STATUS_FAILED,
                'finished_at' => now(),
                'duration_ms' => $duration,
                'error_message' => mb_substr($e->getMessage(), 0, 5000),
            ]);

            $export->update(['status' => ProductExport::STATUS_FAILED]);

            Log::warning('product_export.generation_failed', [
                'export_id' => $export->id,
                'preset' => $export->preset,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function resolvePreset(ProductExport $export): PresetInterface
    {
        $preset = $this->presetRegistry->resolve($export->preset);
        if (! $preset) {
            throw new RuntimeException("Пресет «{$export->preset}» не найден.");
        }

        return $preset;
    }

    /**
     * Запись в tmp + атомарный rename. Возвращает размер записанного файла в байтах.
     */
    protected function writeAtomic(ProductExport $export, PresetInterface $preset): int
    {
        $finalPath = $export->getCacheFilePath();
        $cacheDir = dirname($finalPath);
        if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0755, true) && ! is_dir($cacheDir)) {
            throw new RuntimeException("Не удалось создать каталог кэша: {$cacheDir}");
        }

        $tmpPath = $finalPath.'.tmp.'.getmypid();

        $stream = fopen($tmpPath, 'w');
        if ($stream === false) {
            throw new RuntimeException("Не удалось открыть tmp-файл для записи: {$tmpPath}");
        }

        try {
            try {
                $preset->writeToStream($stream, $export);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! rename($tmpPath, $finalPath)) {
                throw new RuntimeException("Не удалось переименовать tmp-файл в финальный: {$finalPath}");
            }
        } catch (Throwable $e) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
            throw $e;
        }

        clearstatcache(true, $finalPath);

        return (int) filesize($finalPath);
    }
}
