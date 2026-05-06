<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateProductExportJob;
use App\Models\ProductExport;
use App\Services\ProductExport\Presets\PresetInterface;
use App\Services\ProductExport\Presets\PresetRegistry;
use App\Services\ProductExportService;
use Illuminate\Http\JsonResponse;
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

        // Пресетная выгрузка — асинхронный пайплайн.
        if ($export->isPreset()) {
            return $this->handlePresetDownload($export);
        }

        // Кастомная выгрузка — оригинальная синхронная логика.
        return $this->exportService->generate($export);
    }

    /**
     * Стратегия отдачи пресетной выгрузки:
     *
     *  1) Свежий кэш (< 10 минут) → отдаём моментально.
     *  2) Иначе диспатчим GenerateProductExportJob (ShouldBeUnique гасит дубликаты).
     *     На sync-очереди job выполнится прямо в dispatch → файл готов после refresh().
     *     На async-очереди файл может быть ещё не готов → отдаём stale-копию (если есть)
     *     с заголовком X-Export-Stale, либо 202 со статусом, чтобы клиент ждал.
     */
    protected function handlePresetDownload(ProductExport $export)
    {
        $preset = $this->presetRegistry->resolve($export->preset);
        abort_if(! $preset, 404, 'Формат выгрузки не найден.');

        if ($this->hasFreshCacheFile($export)) {
            return $this->streamCachedFile($export, $preset);
        }

        GenerateProductExportJob::dispatch($export->id);

        $export->refresh();

        if ($this->hasFreshCacheFile($export)) {
            return $this->streamCachedFile($export, $preset);
        }

        if ($this->hasAnyCacheFile($export)) {
            return $this->streamCachedFile($export, $preset, stale: true);
        }

        return $this->pendingResponse($export);
    }

    protected function hasFreshCacheFile(ProductExport $export): bool
    {
        if (! $export->hasFreshCache()) {
            return false;
        }

        $filePath = $export->getCacheFilePath();

        return file_exists($filePath) && filesize($filePath) > 0;
    }

    protected function hasAnyCacheFile(ProductExport $export): bool
    {
        $filePath = $export->getCacheFilePath();

        return file_exists($filePath) && filesize($filePath) > 0;
    }

    protected function streamCachedFile(ProductExport $export, PresetInterface $preset, bool $stale = false)
    {
        $filePath = $export->getCacheFilePath();
        $filename = "export_{$preset->key()}_".now()->format('Y-m-d').".{$preset->fileExtension()}";

        $export->update(['last_downloaded_at' => now()]);

        $headers = ['Content-Type' => $preset->mimeType()];
        if ($stale) {
            $headers['X-Export-Stale'] = '1';
        }

        return response()->download($filePath, $filename, $headers);
    }

    protected function pendingResponse(ProductExport $export): JsonResponse
    {
        $lastRun = $export->lastRun;

        return response()->json([
            'status' => $export->status,
            'message' => 'Выгрузка ставится в очередь, обновите страницу через несколько секунд.',
            'run' => $lastRun ? [
                'id' => $lastRun->id,
                'status' => $lastRun->status,
                'started_at' => $lastRun->started_at?->toISOString(),
            ] : null,
        ], 202);
    }
}
