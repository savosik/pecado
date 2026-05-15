<?php

namespace App\Services\ProductExport;

use App\Models\ProductExport;
use App\Models\ProductExportRun;
use App\Services\ProductExport\Presets\AbstractPreset;
use App\Services\ProductExport\Presets\CustomFieldsPreset;
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
     *
     * $queuedForMs — сколько джоба провисела в очереди до старта генерации;
     * Job передаёт это значение, рассчитанное от ProductExport.queued_at до now().
     * Нужно, чтобы видеть отдельно «висели в очереди» и «генерировались».
     */
    public function generate(ProductExport $export, ?int $queuedForMs = null): ProductExportRun
    {
        $preset = $this->resolvePreset($export);

        $timer = new StepTimer;
        if ($preset instanceof TimerAware) {
            $preset->setStepTimer($timer);
        }

        // Снимок версии данных каталога ДО старта генерации. Если ERP/observer
        // bump'нет версию во время генерации — мы запишем старый snapshot, и
        // hasFreshCache корректно поймёт, что нужна повторная регенерация.
        $dataVersionSnapshot = app(ProductExportDataVersion::class)->current();

        $run = ProductExportRun::create([
            'product_export_id' => $export->id,
            'status' => ProductExportRun::STATUS_GENERATING,
            'started_at' => now(),
            'queued_for_ms' => $queuedForMs,
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
                // AbstractPreset и CustomFieldsPreset оба умеют считать строки;
                // method_exists короче, чем плодить ещё один интерфейс ради одного метода.
                'rows_count' => method_exists($preset, 'getRowsProcessed') ? $preset->getRowsProcessed() : null,
                'steps_json' => $this->buildStepsPayload($timer, $duration, $preset),
            ]);

            $export->update([
                'status' => ProductExport::STATUS_READY,
                'cached_at' => now(),
                'data_version_at' => $dataVersionSnapshot->getTimestamp() > 0
                    ? $dataVersionSnapshot
                    : now(),
                // rows_count точнее, чем count(*) с фильтрами на saving —
                // он учитывает только реально обработанные пресетом строки.
                'estimated_rows' => method_exists($preset, 'getRowsProcessed')
                    ? $preset->getRowsProcessed()
                    : $export->estimated_rows,
            ]);

            return $run->fresh();
        } catch (Throwable $e) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            $run->update([
                'status' => ProductExportRun::STATUS_FAILED,
                'finished_at' => now(),
                'duration_ms' => $duration,
                'error_message' => mb_substr($e->getMessage(), 0, 5000),
                'steps_json' => $this->buildStepsPayload($timer, $duration, $preset),
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

    /**
     * Превращает накопленные таймером значения в payload для steps_json.
     * Дописывает «other» как разницу между общим duration и суммой замеренных
     * шагов — туда попадает overhead на update() моделей, fsync, rename,
     * mkdir и всё, что мы явно не замеряли.
     *
     * Если пресет умеет в per-field breakdown (CustomFieldsPreset), добавляет
     * его в payload под ключом `field_breakdown` — это уже не int, а массив
     * объектов; UI отрисует отдельно, а Eloquent cast 'array' переварит.
     *
     * @return array<string, mixed>|null
     */
    protected function buildStepsPayload(StepTimer $timer, int $totalDurationMs, ?PresetInterface $preset = null): ?array
    {
        $steps = $timer->toArray();
        if ($steps === []) {
            return null;
        }

        $known = array_sum($steps);
        $other = $totalDurationMs - $known;
        if ($other > 0) {
            $steps['other'] = $other;
        }

        if ($preset !== null && method_exists($preset, 'getFieldBreakdown')) {
            $breakdown = $preset->getFieldBreakdown();
            if (! empty($breakdown)) {
                $steps['field_breakdown'] = $breakdown;
            }
        }

        return $steps;
    }

    /**
     * Резолвит пресет: либо именованный (yml, shopify…), либо внутренний
     * адаптер для кастомных выгрузок (preset = null).
     *
     * Why: кастомные выгрузки изначально шли через синхронный StreamedResponse,
     * но для каталогов 5к+ они упирались в PHP-таймаут. После переезда на этот
     * генератор любая выгрузка кэшируется в storage/app/exports/{hash}, и
     * GenerateProductExportJob может прогревать её в фоне.
     */
    protected function resolvePreset(ProductExport $export): PresetInterface
    {
        if ($export->isPreset()) {
            $preset = $this->presetRegistry->resolve($export->preset);
            if (! $preset) {
                throw new RuntimeException("Пресет «{$export->preset}» не найден.");
            }

            return $preset;
        }

        return app(CustomFieldsPreset::class);
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

        // Создаём .gz-копию рядом для nginx gzip_static — партнёрам отдаём
        // сжатый поток (Content-Encoding: gzip), CSV/JSON жмутся в 5-10 раз.
        $this->maybeGzipForStatic($finalPath, $export);

        return (int) filesize($finalPath);
    }

    /**
     * Записывает рядом с финальным файлом .gz-копию для nginx gzip_static.
     *
     * Why только для текстовых форматов: XLSX = zip-based, повторный gzip почти
     * не сжимает (≤2%) при тех же CPU-затратах. CSV/JSON/XML — отличные
     * кандидаты (нашей 53 МБ JSON ужмётся до ~5-7 МБ).
     *
     * Why стримим, а не file_get_contents+gzencode: 50+ МБ JSON одной строкой
     * в памяти бьёт по soft-лимиту worker'а.
     */
    protected function maybeGzipForStatic(string $path, ProductExport $export): void
    {
        $format = $export->format?->value;
        if (! in_array($format, ['csv', 'json', 'xml'], true)) {
            return;
        }

        $src = fopen($path, 'rb');
        if ($src === false) {
            return;
        }

        $gz = gzopen($path.'.gz', 'wb6');
        if ($gz === false) {
            fclose($src);

            return;
        }

        try {
            while (! feof($src)) {
                $chunk = fread($src, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                gzwrite($gz, $chunk);
            }
        } finally {
            fclose($src);
            gzclose($gz);
        }
    }
}
