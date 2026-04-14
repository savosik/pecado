<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ProductExport;
use App\Services\ProductExport\Presets\PresetRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
        $userPresets = ProductExport::where('user_id', $userId)
            ->whereNotNull('preset')
            ->get()
            ->keyBy('preset');

        $presets = collect($this->presetRegistry->toArray())->map(function ($preset) use ($userPresets) {
            $existing = $userPresets->get($preset['key']);

            return array_merge($preset, [
                'generated' => $existing !== null,
                'download_url' => $existing?->download_url,
                'is_active' => $existing?->is_active ?? false,
                'cached_at' => $existing?->cached_at?->toISOString(),
                'last_downloaded_at' => $existing?->last_downloaded_at?->toISOString(),
                'export_id' => $existing?->id,
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
        abort_if(!$preset, 404, 'Формат выгрузки не найден.');

        $userId = Auth::id();

        // Ищем существующую запись
        $export = ProductExport::where('user_id', $userId)
            ->where('preset', $presetKey)
            ->first();

        if (!$export) {
            $export = ProductExport::create([
                'user_id' => $userId,
                'client_user_id' => $userId,
                'name' => $preset->name(),
                'format' => $preset->fileExtension(),
                'preset' => $presetKey,
                'filters' => [],
                'fields' => [],
                'is_active' => true,
            ]);
        }

        return response()->json([
            'download_url' => $export->download_url,
            'hash' => $export->hash,
            'export_id' => $export->id,
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
