<?php

namespace App\Console\Commands;

use App\Models\ProductExport;
use App\Models\ProductExportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Чистит каталог storage/app/exports/:
 *  1) orphaned-файлы (нет соответствующего ProductExport.hash в БД) —
 *     удаляем безусловно, даже если файл свежий;
 *  2) stale-файлы по существующим выгрузкам — last_downloaded_at старше N дней
 *     ИЛИ cached_at старше M дней (и ничего не скачивали с тех пор).
 *
 * Удаляет и .gz-копию вместе с base-файлом.
 *
 * Запускается по расписанию (см. routes/console.php). Поддерживает --dry-run
 * для проверки перед боевым прогоном.
 */
class CleanupProductExports extends Command
{
    protected $signature = 'exports:cleanup
        {--dry-run : Только показать что будет удалено, без удаления}
        {--downloaded-days=90 : Удалить файлы, не скачивавшиеся дольше этого срока}
        {--cached-days=30 : Удалить файлы с cached_at старше этого срока (если last_downloaded пуст)}
        {--tmp-hours=2 : Удалить orphaned *.tmp.* файлы старше этого срока в часах (timeout=25 мин + buffer)}
        {--keep-runs-per-export=200 : Оставлять не больше N последних запусков на одну выгрузку в product_export_runs (0 = не чистить)}';

    protected $description = 'Очистка orphaned- и stale-файлов кеша product-выгрузок';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $downloadedDays = (int) $this->option('downloaded-days');
        $cachedDays = (int) $this->option('cached-days');
        $tmpHours = (int) $this->option('tmp-hours');

        $cacheDir = storage_path('app/exports');
        if (! is_dir($cacheDir)) {
            $this->info("Каталог {$cacheDir} не существует, чистить нечего.");

            return self::SUCCESS;
        }

        $this->info($dryRun ? '[dry-run] Поиск файлов на удаление…' : 'Очистка кеша выгрузок…');

        $validHashes = array_flip(ProductExport::pluck('hash')->all());

        // Карта порогов на удаление по hash — чтобы за один проход и orphan, и stale.
        $stalehashes = $this->collectStaleHashes($downloadedDays, $cachedDays);

        $tmpCutoff = time() - max(1, $tmpHours) * 3600;

        $orphaned = 0;
        $stale = 0;
        $tmpOld = 0;
        $skipped = 0;
        $bytesFreed = 0;

        foreach (glob($cacheDir.'/*') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $name = basename($file);
            $size = (int) @filesize($file);

            // *.tmp.{pid} — Generator пишет в них и переименовывает в финальный
            // файл. При нормальной работе всегда удаляются сам Generator через
            // try/catch. Но если процесс killed (-9, OOM, supervisor stop) —
            // tmp файл остаётся навсегда: cleanup чистит его, если он старше
            // двух часов (двойной запас сверх timeout=25 мин + retry с backoff).
            if (str_contains($name, '.tmp.')) {
                $mtime = (int) @filemtime($file);
                if ($mtime > 0 && $mtime < $tmpCutoff) {
                    $tmpOld++;
                    $bytesFreed += $size;
                    $this->line(sprintf('  [tmp]    %s (%s, age=%dч)', $name, $this->formatBytes($size), (int) ((time() - $mtime) / 3600)));
                    if (! $dryRun) {
                        @unlink($file);
                    }
                }

                continue;
            }

            // Имя файла = hash; .gz-копия = hash.gz.
            $hash = preg_replace('/\.gz$/', '', $name);

            if (! isset($validHashes[$hash])) {
                $orphaned++;
                $bytesFreed += $size;
                $this->line(sprintf('  [orphan] %s (%s)', $name, $this->formatBytes($size)));
                if (! $dryRun) {
                    @unlink($file);
                }

                continue;
            }

            if (isset($stalehashes[$hash])) {
                $stale++;
                $bytesFreed += $size;
                $this->line(sprintf('  [stale]  %s (%s, причина: %s)', $name, $this->formatBytes($size), $stalehashes[$hash]));
                if (! $dryRun) {
                    @unlink($file);
                }

                continue;
            }

            $skipped++;
        }

        $runsTrimmed = $this->trimRunHistory(
            (int) $this->option('keep-runs-per-export'),
            $dryRun,
        );

        $summary = sprintf(
            '%s orphaned=%d, stale=%d, tmp_old=%d, kept=%d, освобождено=%s, runs_trimmed=%d',
            $dryRun ? '[dry-run]' : 'Готово.',
            $orphaned,
            $stale,
            $tmpOld,
            $skipped,
            $this->formatBytes($bytesFreed),
            $runsTrimmed,
        );
        $this->info($summary);

        if (! $dryRun) {
            Log::info('exports:cleanup', [
                'orphaned' => $orphaned,
                'stale' => $stale,
                'tmp_old' => $tmpOld,
                'kept' => $skipped,
                'bytes_freed' => $bytesFreed,
                'runs_trimmed' => $runsTrimmed,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Удаляет старые product_export_runs, оставляя не больше $keep последних
     * на каждую выгрузку. Без этого history растёт на ~600 строк/день при
     * warmup каждые 15 мин — за год ~200к строк. Не катастрофа, но мусор
     * копится без причины: последние десятки run-ов уже дают полную картину
     * по duration_ms и ошибкам, более старые — это статистика, для которой
     * есть отдельные системы мониторинга.
     *
     * Защита от удаления текущего last_run_id: ON DELETE SET NULL у FK не
     * страшно, но мы дополнительно отрезаем по id < min(keep_ids).
     */
    protected function trimRunHistory(int $keep, bool $dryRun): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $totalDeleted = 0;

        ProductExport::query()
            ->select('id')
            ->chunkById(500, function ($exports) use ($keep, $dryRun, &$totalDeleted) {
                foreach ($exports as $export) {
                    $keepIds = ProductExportRun::query()
                        ->where('product_export_id', $export->id)
                        ->orderByDesc('id')
                        ->limit($keep)
                        ->pluck('id');

                    if ($keepIds->isEmpty()) {
                        continue;
                    }

                    $minKeepId = (int) $keepIds->min();

                    $toDelete = ProductExportRun::query()
                        ->where('product_export_id', $export->id)
                        ->where('id', '<', $minKeepId);

                    $count = (int) $toDelete->count();
                    if ($count === 0) {
                        continue;
                    }

                    if (! $dryRun) {
                        $toDelete->delete();
                    }

                    $this->line(sprintf('  [runs]   export #%d: %d старых run-ов %s', $export->id, $count, $dryRun ? '(dry-run)' : 'удалено'));
                    $totalDeleted += $count;
                }
            });

        return $totalDeleted;
    }

    /**
     * Возвращает [hash => reason] для всех выгрузок, кеш которых
     * нужно удалить как протухший.
     *
     * @return array<string, string>
     */
    protected function collectStaleHashes(int $downloadedDays, int $cachedDays): array
    {
        $result = [];
        $downloadCutoff = now()->subDays($downloadedDays);
        $cacheCutoff = now()->subDays($cachedDays);

        ProductExport::query()
            ->select('id', 'hash', 'last_downloaded_at', 'cached_at')
            ->chunk(500, function ($exports) use (&$result, $downloadCutoff, $cacheCutoff) {
                foreach ($exports as $export) {
                    if ($export->last_downloaded_at && $export->last_downloaded_at < $downloadCutoff) {
                        $result[$export->hash] = "last_downloaded_at < {$downloadCutoff->toDateString()}";

                        continue;
                    }

                    if (! $export->last_downloaded_at && $export->cached_at && $export->cached_at < $cacheCutoff) {
                        $result[$export->hash] = "cached_at < {$cacheCutoff->toDateString()} (never downloaded)";
                    }
                }
            });

        return $result;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} Б";
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f КБ', $bytes / 1024);
        }

        return sprintf('%.2f МБ', $bytes / 1024 / 1024);
    }
}
