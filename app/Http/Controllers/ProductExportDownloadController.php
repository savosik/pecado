<?php

namespace App\Http\Controllers;

use App\Models\ProductExport;
use App\Services\ProductExport\Presets\PresetInterface;
use App\Services\ProductExport\Presets\PresetRegistry;
use App\Services\ProductExportService;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

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
     * Стратегия ленивой синхронной генерации с TTL кэша 10 минут.
     *
     * 1) Свежий кэш (< 10 минут) → отдаём мгновенно.
     * 2) Иначе → синхронно генерируем файл и отдаём. Cache::lock сериализует параллельные
     *    запросы к одной выгрузке: только один процесс реально пишет файл, остальные ждут
     *    и получают готовый результат.
     */
    protected function handlePresetDownload(ProductExport $export)
    {
        $preset = $this->presetRegistry->resolve($export->preset);
        abort_if(! $preset, 404, 'Формат выгрузки не найден.');

        if ($this->hasFreshCacheFile($export)) {
            return $this->streamCachedFile($export, $preset);
        }

        // До 5 минут на синхронную генерацию. Учти fastcgi_read_timeout nginx —
        // при превышении клиент получит 504, поэтому пресеты должны укладываться в бюджет.
        set_time_limit(300);

        Cache::lock("product-export-generate:{$export->id}", 300)
            ->block(60, function () use ($export, $preset) {
                // Перечитываем cached_at — пока ждали лок, другой процесс мог сгенерировать.
                $export->refresh();
                if (! $this->hasFreshCacheFile($export)) {
                    $this->generateCacheFile($export, $preset);
                }
            });

        return $this->streamCachedFile($export, $preset);
    }

    protected function hasFreshCacheFile(ProductExport $export): bool
    {
        if (! $export->hasFreshCache()) {
            return false;
        }

        $filePath = $export->getCacheFilePath();

        return file_exists($filePath) && filesize($filePath) > 0;
    }

    protected function generateCacheFile(ProductExport $export, PresetInterface $preset): void
    {
        $filePath = $export->getCacheFilePath();
        $cacheDir = dirname($filePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Пишем во временный файл, затем атомарно заменяем — чтобы
        // параллельные читатели не получили наполовину записанный файл.
        $tmpPath = $filePath.'.tmp.'.getmypid();

        try {
            $stream = fopen($tmpPath, 'w');
            $preset->writeToStream($stream, $export);
            if (is_resource($stream)) {
                fclose($stream);
            }

            rename($tmpPath, $filePath);
            $export->update(['cached_at' => now()]);
        } catch (\Throwable $e) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
            throw $e;
        }
    }

    protected function streamCachedFile(ProductExport $export, PresetInterface $preset)
    {
        $filePath = $export->getCacheFilePath();
        $filename = "export_{$preset->key()}_".now()->format('Y-m-d').".{$preset->fileExtension()}";

        $export->update(['last_downloaded_at' => now()]);

        return response()->download($filePath, $filename, [
            'Content-Type' => $preset->mimeType(),
        ]);
    }
}
