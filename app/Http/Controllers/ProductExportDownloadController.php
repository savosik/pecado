<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateProductExportJob;
use App\Models\ProductExport;
use App\Services\ProductExport\Presets\CustomFieldsPreset;
use App\Services\ProductExport\Presets\PresetInterface;
use App\Services\ProductExport\Presets\PresetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ProductExportDownloadController extends Controller
{
    public function __construct(
        protected PresetRegistry $presetRegistry,
    ) {}

    /**
     * Download an export file by hash.
     * This is a public route — security is ensured by the hash uniqueness.
     *
     * Both custom and preset exports пройдут одинаковый асинхронный пайплайн
     * с кэшированием в storage/app/exports/{hash} и фоновой прогрев-генерацией
     * через GenerateProductExportJob.
     */
    public function download(string $hash)
    {
        $export = ProductExport::where('hash', $hash)
            ->where('is_active', true)
            ->firstOrFail();

        return $this->handleAsyncDownload($export);
    }

    /**
     * Стратегия отдачи выгрузки (общая для preset и custom):
     *
     *  1) Свежий кэш (< 10 минут) → отдаём моментально.
     *  2) Иначе диспатчим GenerateProductExportJob (ShouldBeUnique гасит дубликаты).
     *     На sync-очереди job выполнится прямо в dispatch → файл готов после refresh().
     *     На async-очереди файл может быть ещё не готов → отдаём stale-копию (если есть)
     *     с заголовком X-Export-Stale, либо 202 со статусом, чтобы клиент ждал.
     */
    protected function handleAsyncDownload(ProductExport $export)
    {
        $preset = $export->isPreset()
            ? $this->presetRegistry->resolve($export->preset)
            : app(CustomFieldsPreset::class);
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
        // Для кастомных выгрузок имя/MIME берём из $export->format, потому что
        // PresetInterface не получает контекст экспорта в fileExtension()/mimeType()
        // и не может отдать корректное значение вне writeToStream().
        if ($export->isPreset()) {
            $extension = $preset->fileExtension();
            $mimeType = $preset->mimeType();
            $slug = $preset->key();
        } else {
            $extension = $export->format->extension();
            $mimeType = match ($export->format) {
                \App\Enums\ExportFormat::JSON => 'application/json; charset=utf-8',
                \App\Enums\ExportFormat::XML => 'application/xml; charset=utf-8',
                \App\Enums\ExportFormat::XLS => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => 'text/csv; charset=utf-8',
            };
            $slug = \Illuminate\Support\Str::slug($export->name ?: 'export', '_');
        }

        $filename = "export_{$slug}_".now()->format('Y-m-d').".{$extension}";

        $export->update(['last_downloaded_at' => now()]);

        $headers = ['Content-Type' => $mimeType];
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
