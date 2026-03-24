<?php

namespace App\Console\Commands;

use App\Jobs\DownloadProductMediaJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchMissingMedia extends Command
{
    protected $signature = 'catalog:fetch-missing-media
        {--dry-run : Показать что будет загружено, без отправки в очередь}';

    protected $description = 'Поиск и загрузка медиа для товаров без главного изображения через API sex-opt.ru';

    private string $baseUrl = 'https://backend.sex-opt.ru/api/v3/shop/products';
    private string $token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvYmFja2VuZC5zZXgtb3B0LnJ1XC9hcGlcL3YzXC9hdXRoXC9qd3QiLCJpYXQiOjE3NzQzNTQ1NjgsImV4cCI6MTc4OTkwNjU2OCwibmJmIjoxNzc0MzU0NTY4LCJqdGkiOiI4VmRuMTlBS0VUb1FlWXhCIiwic3ViIjo0NDQ2LCJwcnYiOiI0YWMwNWMwZjhhYzA4ZjM2NGNiNGQwM2ZiOGUxZjYzMWZlYzMyMmU4Iiwic2Vzc2lvbl90b2tlbiI6IjcyODgzNTBjNjEwYWViNDdmNmZhNjZkZTc2ODVlZjM1In0.V2AB5xn2qHIeTFN6eSMmxjMy6VIJf5QDZt0ze9QPkuA';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Найти товары без главного изображения
        $products = Product::whereDoesntHave('media', function ($q) {
            $q->where('collection_name', 'main');
        })->get(['id', 'code', 'sku', 'name']);

        $total = $products->count();

        if ($total === 0) {
            $this->info('Все товары имеют главное изображение.');
            return self::SUCCESS;
        }

        $this->info("Товаров без главного изображения: {$total}");
        if ($dryRun) {
            $this->warn('Режим dry-run: джобы не будут отправлены');
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Обработка...');
        $bar->start();

        $dispatched = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($products as $product) {
            $searchTerm = $product->sku ?: $product->code;
            $bar->setMessage("{$searchTerm}");

            if (empty($searchTerm)) {
                $notFound++;
                $bar->advance();
                continue;
            }

            try {
                // Шаг 1: Поиск товара по артикулу
                $searchResponse = Http::timeout(15)
                    ->withToken($this->token)
                    ->get($this->baseUrl, [
                        'search' => $searchTerm,
                        'force_flat' => 'true',
                        'search_availability' => 'any',
                    ]);

                if (!$searchResponse->successful()) {
                    $errors++;
                    $bar->advance();
                    continue;
                }

                $payload = $searchResponse->json('payload', []);

                if (empty($payload)) {
                    $notFound++;
                    $bar->advance();
                    continue;
                }

                $remoteProductId = $payload[0]['id'] ?? null;

                if (!$remoteProductId) {
                    $notFound++;
                    $bar->advance();
                    continue;
                }

                // Шаг 2: Получить детали товара с изображениями
                $detailResponse = Http::timeout(15)
                    ->withToken($this->token)
                    ->get("{$this->baseUrl}/{$remoteProductId}");

                if (!$detailResponse->successful()) {
                    $errors++;
                    $bar->advance();
                    continue;
                }

                $detail = $detailResponse->json();
                $images = $detail['images'] ?? [];
                $videos = $detail['videos'] ?? [];

                if (empty($images)) {
                    $notFound++;
                    $bar->advance();
                    continue;
                }

                // Первое изображение — главное, остальные — дополнительные
                $mainImage = $images[0]['url'] ?? '';
                $additionalImages = collect(array_slice($images, 1))
                    ->pluck('url')
                    ->filter()
                    ->values()
                    ->toArray();

                $videoUrls = collect($videos)->pluck('url')->filter()->values()->toArray();

                if ($dryRun) {
                    $this->newLine();
                    $this->line("  [{$searchTerm}] → ID {$remoteProductId}: главная + " . count($additionalImages) . " доп.");
                } else {
                    $itemData = [
                        'image_main' => $mainImage,
                        'additional_images' => $additionalImages,
                        'product_videos' => $videoUrls,
                    ];

                    DownloadProductMediaJob::dispatch($product->id, $itemData);
                }

                $dispatched++;

                // Пауза между запросами чтобы не превысить rate limit (250/мин)
                usleep(200000); // 200мс

            } catch (\Exception $e) {
                Log::warning("FetchMissingMedia: ошибка для {$searchTerm}: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('═══════════════════════════════════════');
        $this->info('      Поиск медиа завершён');
        $this->info('═══════════════════════════════════════');
        $this->line("  Найдено и отправлено:  {$dispatched}");
        $this->line("  Не найдено:            {$notFound}");
        $this->line("  Ошибки:                {$errors}");
        if (!$dryRun) {
            $this->line("  Очередь:               catalog-media");
        }
        $this->info('═══════════════════════════════════════');

        return self::SUCCESS;
    }
}
