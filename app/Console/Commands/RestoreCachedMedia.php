<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Product;
use App\Models\Scopes\HiddenScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestoreCachedMedia extends Command
{
    protected $signature = 'catalog:restore-cached-media
        {--dry-run : Показать что будет восстановлено, без изменений в БД}';

    protected $description = 'Восстановить медиа для товаров из кэша MinIO (без скачивания файлов из интернета)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $disk = config('media-library.disk_name');

        // Товары без главного изображения
        $products = Product::withoutGlobalScope(HiddenScope::class)
            ->whereDoesntHave('media', fn ($q) => $q->where('collection_name', 'main'))
            ->whereNotNull('code')
            ->get(['id', 'code', 'name']);

        $total = $products->count();

        if ($total === 0) {
            $this->info('Все товары имеют главное изображение.');

            return self::SUCCESS;
        }

        $this->info("Товаров без главного изображения: {$total}");

        if ($dryRun) {
            $this->warn('Режим dry-run: изменения в БД не будут применены');
        }

        $restored = 0;
        $notCached = 0;

        foreach ($products as $product) {
            $code = $product->code;
            $basePath = "products-media/{$code}";

            $collections = [
                'main'       => ['singleFile' => true],
                'additional' => ['singleFile' => false],
                'video'      => ['singleFile' => true],
            ];

            $hasMain = false;

            foreach ($collections as $collection => $opts) {
                $files = Storage::disk($disk)->files("{$basePath}/{$collection}");
                // Фильтрами не трогаем конверсии
                $files = array_filter($files, fn ($f) => ! str_contains($f, '/conversions/') && ! str_contains($f, '/responsive-images/'));

                if (empty($files)) {
                    continue;
                }

                if ($collection === 'main') {
                    $hasMain = true;
                }

                if ($dryRun) {
                    $this->line("  ✓ [{$code}] '{$product->name}' → {$collection}: ".count($files).' файл(ов)');

                    continue;
                }

                $order = 1;
                foreach ($files as $filePath) {
                    $filename = basename($filePath);
                    $mimeType = Storage::disk($disk)->mimeType($filePath);
                    $size = Storage::disk($disk)->size($filePath);

                    $media = new Media;
                    $media->model_type = Product::class;
                    $media->model_id = $product->id;
                    $media->uuid = (string) Str::uuid();
                    $media->collection_name = $collection;
                    $media->name = pathinfo($filename, PATHINFO_FILENAME);
                    $media->file_name = $filename;
                    $media->mime_type = $mimeType;
                    $media->disk = $disk;
                    $media->conversions_disk = $disk;
                    $media->size = $size;
                    $media->custom_properties = ['product_code' => $code];
                    $media->generated_conversions = [];
                    $media->responsive_images = [];
                    $media->manipulations = [];
                    $media->order_column = $order++;
                    $media->save();

                    if ($opts['singleFile']) {
                        break; // одиночная коллекция — берём только первый файл
                    }
                }
            }

            if ($hasMain) {
                $restored++;
            } else {
                $notCached++;
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('  Восстановление медиа из кэша MinIO');
        $this->info('═══════════════════════════════════════');
        $this->line("  Всего без медиа:    {$total}");
        $this->line("  Восстановлено:      {$restored}");
        $this->line("  Нет в кэше:         {$notCached}");

        if ($notCached > 0) {
            $this->newLine();
            $this->warn("  Для {$notCached} товаров кэш не найден. Используйте catalog:fetch-missing-media.");
        }

        $this->info('═══════════════════════════════════════');

        return self::SUCCESS;
    }
}
