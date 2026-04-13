<?php

namespace App\Http\Controllers;

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
     * Обработка скачивания пресетной выгрузки с поддержкой кэширования.
     */
    protected function handlePresetDownload(ProductExport $export)
    {
        $preset = $this->presetRegistry->resolve($export->preset);
        abort_if(!$preset, 404, 'Формат выгрузки не найден.');

        // Проверяем свежесть кэша (4 часа)
        if ($export->hasFreshCache(4)) {
            $filePath = $export->getCacheFilePath();
            $filename = "export_{$preset->key()}_" . now()->format('Y-m-d') . ".{$preset->fileExtension()}";

            $export->update(['last_downloaded_at' => now()]);

            return response()->download($filePath, $filename, [
                'Content-Type' => $preset->mimeType(),
            ]);
        }

        // Генерируем и кэшируем
        $this->generateAndCache($export, $preset);

        $export->update(['last_downloaded_at' => now()]);

        // Отдаём кэшированный файл
        $filePath = $export->getCacheFilePath();
        $filename = "export_{$preset->key()}_" . now()->format('Y-m-d') . ".{$preset->fileExtension()}";

        return response()->download($filePath, $filename, [
            'Content-Type' => $preset->mimeType(),
        ]);
    }

    /**
     * Генерирует файл пресета и сохраняет в кэш.
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
