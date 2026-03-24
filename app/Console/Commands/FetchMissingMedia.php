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

        // ═══ ШАГ 1: Параллельный поиск по SKU/code ═══
        $this->info('Шаг 1/2: Поиск товаров на sex-opt.ru (параллельно)...');

        $searchTerms = [];
        foreach ($products as $product) {
            $term = $product->sku ?: $product->code;
            if (!empty($term)) {
                $searchTerms[$product->id] = [
                    'term' => $term,
                    'product' => $product,
                ];
            }
        }

        // Batch search requests in chunks of 10
        $remoteIds = []; // productId => remoteId
        $chunks = array_chunk($searchTerms, 5, true);

        foreach ($chunks as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                foreach ($chunk as $productId => $info) {
                    $pool->as("search_{$productId}")
                        ->withToken($this->token)
                        ->timeout(5)
                        ->connectTimeout(3)
                        ->get($this->baseUrl, [
                            'search' => $info['term'],
                            'force_flat' => 'true',
                            'search_availability' => 'any',
                        ]);
                }
            });

            foreach ($chunk as $productId => $info) {
                $response = $responses["search_{$productId}"] ?? null;

                if ($response && !($response instanceof \Exception) && $response->successful()) {
                    $payload = $response->json('payload', []);
                    if (!empty($payload) && isset($payload[0]['id'])) {
                        $remoteIds[$productId] = [
                            'remoteId' => $payload[0]['id'],
                            'product' => $info['product'],
                            'term' => $info['term'],
                        ];
                    }
                }
            }
        }

        $this->info("  Найдено на sex-opt.ru: " . count($remoteIds) . " из " . count($searchTerms));

        if (empty($remoteIds)) {
            $this->warn('Ни один товар не найден на sex-opt.ru');
            return self::SUCCESS;
        }

        // ═══ ШАГ 2: Параллельное получение деталей с картинками ═══
        $this->info('Шаг 2/2: Загрузка информации о картинках (параллельно)...');

        $dispatched = 0;
        $noImages = 0;
        $detailChunks = array_chunk($remoteIds, 5, true);

        foreach ($detailChunks as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                foreach ($chunk as $productId => $info) {
                    $pool->as("detail_{$productId}")
                        ->withToken($this->token)
                        ->timeout(5)
                        ->connectTimeout(3)
                        ->get("{$this->baseUrl}/{$info['remoteId']}");
                }
            });

            foreach ($chunk as $productId => $info) {
                $response = $responses["detail_{$productId}"] ?? null;

                if (!$response || ($response instanceof \Exception) || !$response->successful()) {
                    continue;
                }

                $detail = $response->json('payload', []);
                $images = $detail['images'] ?? [];

                if (empty($images)) {
                    $noImages++;
                    continue;
                }

                $mainImage = $images[0]['url'] ?? '';
                $additionalImages = collect(array_slice($images, 1))
                    ->pluck('url')
                    ->filter()
                    ->values()
                    ->toArray();

                $videoUrls = collect($detail['videos'] ?? [])
                    ->pluck('url')
                    ->filter()
                    ->values()
                    ->toArray();

                if ($dryRun) {
                    $this->line("  ✓ [{$info['term']}] → ID {$info['remoteId']}: главная + " . count($additionalImages) . " доп.");
                } else {
                    DownloadProductMediaJob::dispatch($info['product']->id, [
                        'image_main' => $mainImage,
                        'additional_images' => $additionalImages,
                        'product_videos' => $videoUrls,
                    ]);
                }

                $dispatched++;
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('      Поиск медиа завершён');
        $this->info('═══════════════════════════════════════');
        $this->line("  Всего без медиа:       {$total}");
        $this->line("  Найдено и отправлено:  {$dispatched}");
        $this->line("  Без изображений:       {$noImages}");
        $this->line("  Не найдено:            " . ($total - $dispatched - $noImages));
        if (!$dryRun && $dispatched > 0) {
            $this->line("  Очередь:               catalog-media");
        }
        $this->info('═══════════════════════════════════════');

        return self::SUCCESS;
    }
}
