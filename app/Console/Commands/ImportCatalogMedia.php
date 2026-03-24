<?php

namespace App\Console\Commands;

use App\Jobs\DownloadProductMediaJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportCatalogMedia extends Command
{
    protected $signature = 'catalog:import-media
        {--url= : URL эндпоинта экспорта}
        {--missing-only : Загружать медиа только для товаров без главного изображения}';

    protected $description = 'Импорт только медиа (изображения, видео) для существующих товаров из XML-эндпоинта';

    public function handle(): int
    {
        $url = $this->option('url')
            ?: 'https://customers.sex-opt.ru/api/public/export/651?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp';

        $missingOnly = $this->option('missing-only');

        $this->info('Загрузка XML-файла каталога...');
        if ($missingOnly) {
            $this->info('Режим: только товары без главного изображения');
        }

        $response = Http::timeout(120)->get($url);

        if (!$response->successful()) {
            $this->error("Ошибка загрузки: HTTP {$response->status()}");
            return self::FAILURE;
        }

        $xmlString = $response->body();
        $this->info('XML загружен (' . number_format(strlen($xmlString)) . ' байт)');

        try {
            $xml = new \SimpleXMLElement($xmlString);
        } catch (\Exception $e) {
            $this->error("Ошибка парсинга XML: {$e->getMessage()}");
            return self::FAILURE;
        }

        $items = $xml->items->item ?? [];
        $totalItems = count($items);

        if ($totalItems === 0) {
            $this->warn('XML не содержит товаров.');
            return self::SUCCESS;
        }

        // Если --missing-only, заранее собираем ID товаров без главного изображения
        $productIdsWithoutMainImage = null;
        if ($missingOnly) {
            $productIdsWithoutMainImage = Product::whereDoesntHave('media', function ($q) {
                $q->where('collection_name', 'main');
            })->pluck('external_id')->flip()->toArray();

            $this->info("Товаров без главного изображения: " . count($productIdsWithoutMainImage));
        }

        $this->info("Найдено товаров в XML: {$totalItems}");

        $bar = $this->output->createProgressBar($totalItems);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Обработка...');
        $bar->start();

        $dispatched = 0;
        $skipped = 0;
        $skippedHasMedia = 0;

        foreach ($items as $item) {
            $uid = (string) $item->uid;
            $name = (string) $item->name;
            $code = (string) $item->code;

            $bar->setMessage(Str::limit($name, 50));

            // Если --missing-only и товар уже имеет главное изображение — пропускаем
            if ($missingOnly && !isset($productIdsWithoutMainImage[$uid])) {
                $skippedHasMedia++;
                $bar->advance();
                continue;
            }

            $product = Product::where('external_id', $uid)->first();

            if (!$product) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $itemData = $this->parseMediaData($item);

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
        $this->line("  Очередь:               catalog-media");
        $this->info('═══════════════════════════════════════');
        $this->newLine();
        $this->info('Обработка выполняется воркерами в фоне.');

        return self::SUCCESS;
    }

    /**
     * Parse only media-related data from a SimpleXMLElement item.
     */
    private function parseMediaData(\SimpleXMLElement $item): array
    {
        $data = [
            'code' => (string) $item->code,
            'name' => (string) $item->name,
            'image_main' => (string) $item->image_main,
        ];

        $data['additional_images'] = [];
        if (isset($item->additional_images->additional_image)) {
            foreach ($item->additional_images->additional_image as $img) {
                $data['additional_images'][] = (string) $img;
            }
        }

        $data['product_videos'] = [];
        if (isset($item->product_videos->product_video)) {
            foreach ($item->product_videos->product_video as $video) {
                $data['product_videos'][] = (string) $video;
            }
        }

        return $data;
    }
}
