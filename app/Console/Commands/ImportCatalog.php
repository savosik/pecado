<?php

namespace App\Console\Commands;

use App\Jobs\ImportCatalogProductJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportCatalog extends Command
{
    protected $signature = 'catalog:import
        {--url= : URL эндпоинта экспорта}
        {--no-media : Пропустить загрузку изображений и видео}';

    protected $description = 'Импорт каталога товаров из внешнего XML-эндпоинта (через очереди)';

    public function handle(): int
    {
        $url = $this->option('url')
            ?: 'https://customers.sex-opt.ru/api/public/export/651?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp';

        $skipMedia = $this->option('no-media');

        $this->info('Загрузка XML-файла каталога...');

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

        $this->info("Найдено товаров: {$totalItems}");
        $this->info($skipMedia ? 'Режим: без загрузки медиа' : 'Режим: с загрузкой медиа');

        $bar = $this->output->createProgressBar($totalItems);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Отправка в очередь...');
        $bar->start();

        $dispatched = 0;

        foreach ($items as $item) {
            $itemData = $this->parseItem($item);

            $bar->setMessage(Str::limit($itemData['name'], 50));

            ImportCatalogProductJob::dispatch($itemData, $skipMedia);

            $dispatched++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('═══════════════════════════════════════');
        $this->info('      Задачи отправлены в очередь');
        $this->info('═══════════════════════════════════════');
        $this->line("  Товаров в очереди:     {$dispatched}");
        $this->line("  Очередь данных:        catalog-import");

        if (!$skipMedia) {
            $this->line("  Очередь медиа:         catalog-media");
        }

        $this->info('═══════════════════════════════════════');
        $this->newLine();
        $this->info('Обработка выполняется воркерами в фоне.');
        $this->info('Следите за прогрессом: tail -f storage/logs/laravel.log');

        return self::SUCCESS;
    }

    /**
     * Parse a SimpleXMLElement item into a plain array for serialization.
     */
    private function parseItem(\SimpleXMLElement $item): array
    {
        $data = [
            'uid' => (string) $item->uid,
            'code' => (string) $item->code,
            'sku' => (string) $item->sku,
            'name' => (string) $item->name,
            'slug' => (string) $item->slug,
            'url' => (string) $item->url,
            'barcode' => (string) $item->barcode,
            'tnved' => (string) $item->tnved,
            'novelty' => (string) $item->novelty,
            'marked' => (string) $item->marked,
            'liquidation' => (string) $item->liquidation,
            'for_marketplaces' => (string) $item->for_marketplaces,
            'category_path' => (string) $item->category_path,
            'brand_name' => (string) $item->brand_name,
            'brand_uid' => (string) $item->brand_uid,
            'model_name' => (string) $item->model_name,
            'model_uid' => (string) $item->model_uid,
            'description' => (string) $item->description,
            'description_html' => trim((string) $item->description_html) ?: null,
            'short_description' => (string) $item->short_description,
            'group_code' => (string) $item->group_code,
            'group_name' => (string) $item->group_name,
            'image_main' => (string) $item->image_main,
        ];

        // Barcodes
        $data['barcodes'] = [];
        if (isset($item->barcodes->barcode)) {
            foreach ($item->barcodes->barcode as $barcode) {
                $data['barcodes'][] = (string) $barcode;
            }
        }

        // Parameters
        $data['parameters'] = [];
        if (isset($item->parameters->parameter)) {
            foreach ($item->parameters->parameter as $parameter) {
                $data['parameters'][] = [
                    'name' => (string) $parameter['name'],
                    'value' => (string) $parameter,
                ];
            }
        }

        // Additional images
        $data['additional_images'] = [];
        if (isset($item->additional_images->additional_image)) {
            foreach ($item->additional_images->additional_image as $img) {
                $data['additional_images'][] = (string) $img;
            }
        }

        // Videos
        $data['product_videos'] = [];
        if (isset($item->product_videos->product_video)) {
            foreach ($item->product_videos->product_video as $video) {
                $data['product_videos'][] = (string) $video;
            }
        }

        // Certificates
        $data['certificates'] = [];
        if (isset($item->certificates->certificate)) {
            foreach ($item->certificates->certificate as $cert) {
                $data['certificates'][] = (string) $cert;
            }
        }

        return $data;
    }
}
