<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateProductExportJob;
use App\Models\ProductExport;
use App\Services\ProductExport\Presets\PresetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ExportPresetController extends Controller
{
    public function __construct(
        protected PresetRegistry $presetRegistry,
    ) {}

    /**
     * Страница стандартных выгрузок (Inertia).
     */
    public function index()
    {
        $userId = Auth::id();

        // Все пресеты пользователя
        $userPresets = ProductExport::with('lastRun')
            ->where('user_id', $userId)
            ->whereNotNull('preset')
            ->get()
            ->keyBy('preset');

        $presets = collect($this->presetRegistry->toArray())->map(function ($preset) use ($userPresets) {
            $existing = $userPresets->get($preset['key']);
            $lastRun = $existing?->lastRun;

            return array_merge($preset, [
                'generated' => $existing !== null,
                'download_url' => $existing?->download_url,
                'is_active' => $existing?->is_active ?? false,
                'cached_at' => $existing?->cached_at?->toISOString(),
                'last_downloaded_at' => $existing?->last_downloaded_at?->toISOString(),
                'export_id' => $existing?->id,
                'status' => $existing?->status ?? ProductExport::STATUS_IDLE,
                'last_run' => $lastRun ? [
                    'status' => $lastRun->status,
                    'duration_ms' => $lastRun->duration_ms,
                    'rows_count' => $lastRun->rows_count,
                    'error_message' => $lastRun->error_message,
                ] : null,
            ]);
        })->toArray();

        return Inertia::render('User/Cabinet/ExportPresets/Index', [
            'presets' => $presets,
        ]);
    }

    /**
     * Сгенерировать (или получить существующую) ссылку на пресет для текущего пользователя.
     */
    public function generate(Request $request, string $presetKey)
    {
        $preset = $this->presetRegistry->resolve($presetKey);
        abort_if(! $preset, 404, 'Формат выгрузки не найден.');

        $userId = Auth::id();

        // Ищем существующую запись
        $export = ProductExport::where('user_id', $userId)
            ->where('preset', $presetKey)
            ->first();

        if (! $export) {
            $export = ProductExport::create([
                'user_id' => $userId,
                'client_user_id' => $userId,
                'name' => $preset->name(),
                'format' => match ($preset->fileExtension()) {
                    'xlsx' => 'xls',
                    default => $preset->fileExtension(),
                },
                'preset' => $presetKey,
                'filters' => [],
                'fields' => [],
                'is_active' => true,
            ]);
        }

        // Сразу ставим задачу — пока пользователь идёт к ссылке, файл уже строится.
        // ShouldBeUnique гарантирует, что параллельные запросы не запустят
        // несколько generation одновременно.
        GenerateProductExportJob::dispatch($export->id);

        return response()->json([
            'download_url' => $export->download_url,
            'hash' => $export->hash,
            'export_id' => $export->id,
            'status' => $export->fresh()->status,
        ]);
    }

    /**
     * Текущий статус генерации пресета: что показывать в карточке во время поллинга.
     */
    public function status(string $presetKey): JsonResponse
    {
        $preset = $this->presetRegistry->resolve($presetKey);
        abort_if(! $preset, 404, 'Формат выгрузки не найден.');

        $export = ProductExport::with('lastRun')
            ->where('user_id', Auth::id())
            ->where('preset', $presetKey)
            ->first();

        if (! $export) {
            return response()->json([
                'status' => ProductExport::STATUS_IDLE,
                'cached_at' => null,
                'last_downloaded_at' => null,
                'download_url' => null,
                'last_run' => null,
            ]);
        }

        $lastRun = $export->lastRun;

        return response()->json([
            'status' => $export->status,
            'cached_at' => $export->cached_at?->toISOString(),
            'last_downloaded_at' => $export->last_downloaded_at?->toISOString(),
            'download_url' => $export->download_url,
            'last_run' => $lastRun ? [
                'id' => $lastRun->id,
                'status' => $lastRun->status,
                'started_at' => $lastRun->started_at?->toISOString(),
                'finished_at' => $lastRun->finished_at?->toISOString(),
                'duration_ms' => $lastRun->duration_ms,
                'rows_count' => $lastRun->rows_count,
                'bytes' => $lastRun->bytes,
                'error_message' => $lastRun->error_message,
            ] : null,
        ]);
    }

    /**
     * Удалить (деактивировать) пресет-выгрузку текущего пользователя.
     */
    public function destroy(string $presetKey)
    {
        $export = ProductExport::where('user_id', Auth::id())
            ->where('preset', $presetKey)
            ->first();

        if ($export) {
            // Удаляем кэшированный файл
            $cachePath = $export->getCacheFilePath();
            if (file_exists($cachePath)) {
                @unlink($cachePath);
            }

            $export->delete();
        }

        return response()->json(['message' => 'Выгрузка удалена']);
    }
}
