<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Scopes\HiddenScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeMediaCache extends Command
{
    protected $signature = 'catalog:purge-media-cache
        {--dry-run : Показать что будет удалено, без реального удаления}';

    protected $description = 'Удалить из MinIO кэшированные медиафайлы товаров, которых больше нет в базе';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $disk = config('media-library.disk_name');

        // Все существующие артикулы товаров
        $existingCodes = Product::withoutGlobalScope(HiddenScope::class)
            ->whereNotNull('code')
            ->pluck('code')
            ->unique()
            ->flip(); // превращаем в set для O(1) поиска

        // Директории в MinIO под products-media/ — каждая директория = один артикул
        $directories = Storage::disk($disk)->directories('products-media');

        $orphans = [];
        foreach ($directories as $dir) {
            $code = basename($dir);
            if (! $existingCodes->has($code)) {
                $orphans[] = ['code' => $code, 'path' => $dir];
            }
        }

        if (empty($orphans)) {
            $this->info('Осиротевших директорий в кэше медиа не найдено.');

            return self::SUCCESS;
        }

        $count = count($orphans);
        $this->info("Найдено осиротевших директорий: {$count}");

        if ($dryRun) {
            $this->warn('Режим dry-run: файлы не будут удалены');
            $this->newLine();
            foreach ($orphans as $item) {
                $this->line("  - products-media/{$item['code']}/");
            }
        } else {
            foreach ($orphans as $item) {
                Storage::disk($disk)->deleteDirectory($item['path']);
                $this->line("  ✓ Удалено: products-media/{$item['code']}/");
            }

            $this->newLine();
            $this->info("Удалено директорий из MinIO: {$count}");
        }

        return self::SUCCESS;
    }
}
