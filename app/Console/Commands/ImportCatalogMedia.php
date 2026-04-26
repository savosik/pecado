<?php

namespace App\Console\Commands;

use App\Jobs\DownloadProductMediaJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportCatalogMedia extends Command
{
    protected $signature = 'catalog:import-media
        {--url= : URL эндпоинта экспорта (JSON)}
        {--missing-only : Загружать медиа только для товаров без главного изображения}';

    protected $description = 'Импорт медиа (изображения, видео) для существующих товаров из JSON-эндпоинта';

    public function handle(): int
    {
        $url = $this->option('url') ?: config('services.sex_opt.export_url');

        if (! is_string($url) || $url === '') {
            $this->error('Не задан URL экспорта (--url= или SEX_OPT_EXPORT_URL в .env / services.sex_opt.export_url)');

            return self::FAILURE;
        }

        $missingOnly = $this->option('missing-only');

        $this->info('Загрузка JSON-файла каталога...');
        if ($missingOnly) {
            $this->info('Режим: только товары без главного изображения');
        }

        $response = Http::timeout(120)->get($url);

        if (! $response->successful()) {
            $this->error("Ошибка загрузки: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $items = $response->json();

        if (! is_array($items) || count($items) === 0) {
            $this->warn('JSON не содержит товаров.');

            return self::SUCCESS;
        }

        $totalItems = count($items);
        $this->info("Найдено товаров в JSON: {$totalItems}");

        // Если --missing-only, заранее собираем external_id товаров без главного изображения
        $productIdsWithoutMainImage = null;
        if ($missingOnly) {
            $productIdsWithoutMainImage = Product::whereDoesntHave('media', function ($q) {
                $q->where('collection_name', 'main');
            })->pluck('external_id')->flip()->toArray();

            $this->info('Товаров без главного изображения: '.count($productIdsWithoutMainImage));
        }

        $bar = $this->output->createProgressBar($totalItems);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Обработка...');
        $bar->start();

        $dispatched = 0;
        $skipped = 0;
        $skippedHasMedia = 0;

        foreach ($items as $item) {
            $uuid = $item['uuid'] ?? '';

            $bar->setMessage($uuid);

            if (empty($uuid)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            // Если --missing-only и товар уже имеет главное изображение — пропускаем
            if ($missingOnly && ! isset($productIdsWithoutMainImage[$uuid])) {
                $skippedHasMedia++;
                $bar->advance();

                continue;
            }

            $product = Product::where('external_id', $uuid)->first();

            if (! $product) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $itemData = [
                'image_main' => $item['image_main'] ?? '',
                'additional_images' => $item['additional_images'] ?? [],
                'product_videos' => $item['videos'] ?? [],
            ];

            DownloadProductMediaJob::dispatch($product->id, $itemData);

            $dispatched++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('═══════════════════════════════════════');
        $this->info('      Импорт медиа завершён');
        $this->info('═══════════════════════════════════════');
        $this->line("  Отправлено в очередь:  {$dispatched}");
        $this->line("  Пропущено (нет в БД): {$skipped}");
        if ($missingOnly) {
            $this->line("  Пропущено (есть медиа): {$skippedHasMedia}");
        }
        $this->line('  Очередь:               catalog-media');
        $this->info('═══════════════════════════════════════');
        $this->newLine();
        $this->info('Обработка выполняется воркерами в фоне.');

        return self::SUCCESS;
    }
}
