<?php

namespace App\Http\Controllers;

use App\Jobs\RegenerateSinglePresetExportJob;
use App\Models\ProductExport;
use App\Services\ProductExport\Presets\PresetRegistry;
use App\Services\ProductExportService;
use Illuminate\Routing\Controller;

class ProductExportDownloadController extends Controller
{
    public function __construct(
        protected ProductExportService $exportService,
        protected PresetRegistry $presetRegistry,
    ) {}

    /**
     * Download an export file by hash.
     * This is a public route — security is ensured by the hash uniqueness.
     *
     * Supports both custom exports and preset exports with caching.
     */
    public function download(string $hash)
    {
        $export = ProductExport::where('hash', $hash)
            ->where('is_active', true)
            ->firstOrFail();

        // Пресетная выгрузка — используем соответствующий генератор
        if ($export->isPreset()) {
            return $this->handlePresetDownload($export);
        }

        // Кастомная выгрузка — оригинальная логика
        return $this->exportService->generate($export);
    }

    /**
     * Стратегия ленивой регенерации:
     *
     * 1) Свежий кэш (< 4 часов) → отдаём мгновенно
     * 2) Стаалый кэш (файл есть, но > 4 часов) → отдаём стаалый + ставим фоновую регенерацию
     * 3) Нет кэша вообще (первый запрос) → генерируем синхронно, сохраняем, отдаём
     *
     * Защита от шторма запросов:
     * - WithoutOverlapping в Job → один export_id = один Job в очереди
     * - Очередь 'exports' с 1 воркером → генерация сериализована
     * - Атомарная замена файла (rename) → читатели не получат полу-файл
     */
    protected function handlePresetDownload(ProductExport $export)
    {
        $preset = $this->presetRegistry->resolve($export->preset);
        abort_if(!$preset, 404, 'Формат выгрузки не найден.');

        $filePath = $export->getCacheFilePath();
        $filename = "export_{$preset->key()}_" . now()->format('Y-m-d') . ".{$preset->fileExtension()}";
        $hasCacheFile = file_exists($filePath) && filesize($filePath) > 0;

        // Случай 1: Свежий кэш — отдаём мгновенно
        if ($export->hasFreshCache(4) && $hasCacheFile) {
            $export->update(['last_downloaded_at' => now()]);

            return response()->download($filePath, $filename, [
                'Content-Type' => $preset->mimeType(),
            ]);
        }

        // Случай 2: Стаалый кэш (файл есть, но устарел или cached_at пустой)
        if ($hasCacheFile) {
            // Ставим фоновую регенерацию (WithoutOverlapping не даст создать дубль)
            RegenerateSinglePresetExportJob::dispatch($export->id);

            $export->update(['last_downloaded_at' => now()]);

            return response()->download($filePath, $filename, [
                'Content-Type' => $preset->mimeType(),
            ]);
        }

        // Случай 3: Нет кэша вообще — генерируем синхронно (только первый раз)
        $this->generateAndCache($export, $preset);
        $export->update(['last_downloaded_at' => now()]);

        return response()->download($filePath, $filename, [
            'Content-Type' => $preset->mimeType(),
        ]);
    }

    /**
     * Генерирует файл пресета и сохраняет в кэш (синхронно).
     * Используется только при первом скачивании, когда файла ещё нет.
     */
    protected function generateAndCache(ProductExport $export, $preset): void
    {
        $cacheDir = dirname($export->getCacheFilePath());
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $filePath = $export->getCacheFilePath();

        $stream = fopen($filePath, 'w');
        $preset->writeToStream($stream, $export);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $export->update(['cached_at' => now()]);
    }
}
