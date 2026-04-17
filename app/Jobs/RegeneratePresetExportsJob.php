<?php

namespace App\Jobs;

use App\Models\ProductExport;
use App\Services\ProductExport\Presets\PresetRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Фоновая регенерация кэшей для всех активных пресетных выгрузок.
 * Запускается по расписанию (Cron), чтобы клиент скачивал файл мгновенно.
 */
class RegeneratePresetExportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 минут

    public function handle(PresetRegistry $presetRegistry): void
    {
        $exports = ProductExport::query()
            ->whereNotNull('preset')
            ->where('is_active', true)
            ->get();

        Log::info("[PresetExports] Начало регенерации кэша для {$exports->count()} выгрузок.");

        foreach ($exports as $export) {
            try {
                $preset = $presetRegistry->resolve($export->preset);
                if (! $preset) {
                    Log::warning("[PresetExports] Неизвестный пресет: {$export->preset} (export #{$export->id})");

                    continue;
                }

                $cacheDir = dirname($export->getCacheFilePath());
                if (! is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }

                $filePath = $export->getCacheFilePath();
                $stream = fopen($filePath, 'w');
                $preset->writeToStream($stream, $export);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $export->update(['cached_at' => now()]);

                Log::info("[PresetExports] Кэш обновлён: {$export->preset} (export #{$export->id})");
            } catch (\Throwable $e) {
                Log::error("[PresetExports] Ошибка генерации {$export->preset} (export #{$export->id}): {$e->getMessage()}");
            }
        }

        Log::info('[PresetExports] Регенерация кэша завершена.');
    }
}
