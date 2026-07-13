<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\MediaLibrary\SanitizingFileNamer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Переименование существующих медиафайлов с «небезопасными» именами.
 *
 * Why: до внедрения {@see SanitizingFileNamer} часть файлов была загружена с
 * кириллицей, запятыми и скобками в имени (например, сгенерированные картинки
 * `ChatGPT-Image-2-июл.-2026-г.,-12_36_43.png`). Такие URL ломают партнёрские
 * выгрузки — запятая внутри имени рвёт список изображений на части. Команда
 * приводит имена к ASCII-виду и переносит на диске и оригинал, и все конверсии
 * (`{id}/conversions/…`), и responsive-images (`{id}/responsive-images/…`),
 * попутно обновляя ссылки в БД.
 *
 * После прогона нужно перегенерировать затронутые партнёрские выгрузки
 * (`exports:warm` или GenerateProductExportJob).
 */
class SanitizeMediaFilenames extends Command
{
    protected $signature = 'media:sanitize-filenames
        {--dry-run : Показать что будет переименовано, без изменений}
        {--ids= : Ограничить конкретными media id (через запятую)}';

    protected $description = 'Переименовать медиафайлы с небезопасными именами (кириллица, запятые, скобки, пробелы) в ASCII';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Media::query();

        if ($ids = $this->option('ids')) {
            $query->whereIn('id', array_filter(array_map('trim', explode(',', $ids))));
        }

        // Любой символ вне безопасного набора [A-Za-z0-9._-] — в т.ч. пробел,
        // запятая, скобки, кириллица. На MySQL/MariaDB фильтруем через REGEXP,
        // на прочих драйверах (SQLite в тестах) — в PHP, т.к. REGEXP там нет.
        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $media = $query->whereRaw('file_name REGEXP ?', ['[^A-Za-z0-9._-]'])->get();
        } else {
            $media = $query->get()
                ->filter(fn (Media $m) => preg_match('/[^A-Za-z0-9._-]/', $m->file_name) === 1)
                ->values();
        }

        $total = $media->count();

        if ($total === 0) {
            $this->info('Медиафайлов с небезопасными именами не найдено.');

            return self::SUCCESS;
        }

        $this->info("Найдено медиафайлов для переименования: {$total}");
        if ($dryRun) {
            $this->warn('Режим dry-run: изменения не применяются');
        }

        $renamed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($media as $item) {
            $oldFile = $item->file_name;
            $ext = pathinfo($oldFile, PATHINFO_EXTENSION);
            $oldBase = pathinfo($oldFile, PATHINFO_FILENAME);
            $newBase = SanitizingFileNamer::sanitizeBaseName($oldBase);
            $newFile = $ext !== '' ? $newBase.'.'.strtolower($ext) : $newBase;

            if ($newFile === $oldFile) {
                $skipped++;

                continue;
            }

            $this->line("#{$item->id}: «{$oldFile}» → «{$newFile}»");

            if ($dryRun) {
                $renamed++;

                continue;
            }

            try {
                $this->renameOnDisks($item, $oldBase, $newBase);

                // Обновляем ссылки на файлы в JSON responsive-images (если есть).
                if ($responsive = $item->responsive_images) {
                    $json = json_encode($responsive, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $json = str_replace($oldBase, $newBase, $json);
                    $item->responsive_images = json_decode($json, true);
                }

                $item->file_name = $newFile;
                $item->save();

                $renamed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  Ошибка на media #{$item->id}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Переименовано: {$renamed}, пропущено: {$skipped}, ошибок: {$errors}");

        if (! $dryRun && $renamed > 0) {
            $this->warn('Не забудьте перегенерировать партнёрские выгрузки: php artisan exports:warm --force');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Перенести на диске оригинал и все производные файлы медиа.
     *
     * Все файлы медиа лежат под папкой `{id}/` (оригинал), `{id}/conversions/`
     * и `{id}/responsive-images/`, а их имена начинаются с базового имени
     * оригинала. Переносим каждый файл, у которого имя начинается со старого
     * базового имени, заменяя только этот префикс (суффиксы `-thumb.jpg`,
     * `-large.jpg` и размеры responsive сохраняются).
     */
    protected function renameOnDisks(Media $media, string $oldBase, string $newBase): void
    {
        $disks = array_unique(array_filter([
            $media->disk,
            $media->conversions_disk,
        ]));

        foreach ($disks as $diskName) {
            $disk = Storage::disk($diskName);
            $prefix = $media->id.'/';

            foreach ($disk->allFiles($prefix) as $path) {
                $dir = trim(substr($path, 0, strrpos($path, '/')), '/');
                $name = basename($path);

                if (! str_starts_with($name, $oldBase)) {
                    continue;
                }

                $newName = $newBase.substr($name, strlen($oldBase));
                $newPath = $dir.'/'.$newName;

                if ($newPath === $path) {
                    continue;
                }

                $disk->move($path, $newPath);
            }
        }
    }
}
