<?php

namespace App\Console\Commands;

use App\Jobs\DownloadProductMediaJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchMissingMedia extends Command
{
    protected $signature = 'catalog:fetch-missing-media
        {--dry-run : Показать что будет загружено, без отправки в очередь}
        {--pool-timeout=15 : Таймаут (сек) для параллельных HTTP-запросов}
        {--retry-timeout=30 : Таймаут (сек) для последовательного retry}
        {--pool-size=5 : Размер параллельной пачки}';

    protected $description = 'Поиск и загрузка медиа для товаров без главного изображения через API sex-opt.ru';

    private string $baseUrl = 'https://backend.sex-opt.ru/api/v3/shop/products';

    private string $token = '';

    public function handle(): int
    {
        $token = (string) (config('services.sex_opt.api_token') ?? '');
        if ($token === '') {
            $this->error('Не задан токен sex-opt API (SEX_OPT_API_TOKEN в .env / services.sex_opt.api_token)');

            return self::FAILURE;
        }
        $this->token = $token;

        $dryRun = (bool) $this->option('dry-run');
        $poolTimeout = (int) $this->option('pool-timeout');
        $retryTimeout = (int) $this->option('retry-timeout');
        $poolSize = (int) $this->option('pool-size');

        $products = Product::with('barcodes:id,product_id,barcode')
            ->whereDoesntHave('media', function ($q) {
                $q->where('collection_name', 'main');
            })
            ->get(['id', 'code', 'name']);

        $total = $products->count();

        if ($total === 0) {
            $this->info('Все товары имеют главное изображение.');

            return self::SUCCESS;
        }

        $this->info("Товаров без главного изображения: {$total}");
        if ($dryRun) {
            $this->warn('Режим dry-run: джобы не будут отправлены');
        }

        $searchTerms = [];
        foreach ($products as $product) {
            $code = (string) ($product->code ?? '');
            $barcodes = $product->barcodes
                ->pluck('barcode')
                ->filter(fn ($b) => (string) $b !== '')
                ->values()
                ->all();

            $term = $code !== '' ? $code : ($barcodes[0] ?? null);
            if (! empty($term)) {
                $searchTerms[$product->id] = [
                    'term' => $term,
                    'product' => $product,
                    'barcodes' => $barcodes,
                ];
            }
        }

        // ═══ ШАГ 1: Параллельный поиск по code/barcode ═══
        // sku/article намеренно не используется — у sex-opt он часто дублируется между разными товарами.
        $this->info("Шаг 1/2: Поиск товаров на sex-opt.ru (параллельно, pool={$poolSize}, timeout={$poolTimeout}s)...");

        [$remoteIds, $failedSearch, $notMatched] = $this->searchBatch(
            $searchTerms,
            $poolSize,
            $poolTimeout,
            parallel: true,
        );

        // Последовательный retry для тех, кого не дозвались параллельно
        if (! empty($failedSearch)) {
            $this->info('  Retry (sequential, timeout='.$retryTimeout.'s): '.count($failedSearch).' запросов...');
            [$retryFound, $stillFailed, $retryNotMatched] = $this->searchBatch(
                $failedSearch,
                1,
                $retryTimeout,
                parallel: false,
            );
            $remoteIds += $retryFound;
            $failedSearch = $stillFailed;
            $notMatched = array_merge($notMatched, $retryNotMatched);
        }

        $found = count($remoteIds);
        $this->info("  Найдено на sex-opt.ru: {$found} из ".count($searchTerms));
        if (! empty($notMatched)) {
            $this->warn('  Не совпал code/barcode в результатах поиска: '.count($notMatched));
        }
        if (! empty($failedSearch)) {
            $this->warn('  HTTP-ошибки/таймауты (даже после retry): '.count($failedSearch));
        }

        if (empty($remoteIds)) {
            $this->warn('Ни один товар не найден на sex-opt.ru');

            return self::SUCCESS;
        }

        // ═══ ШАГ 2: Параллельное получение деталей с картинками ═══
        $this->info("Шаг 2/2: Загрузка информации о картинках (параллельно, pool={$poolSize}, timeout={$poolTimeout}s)...");

        [$dispatched, $noImages, $failedDetail] = $this->fetchDetailsBatch(
            $remoteIds,
            $poolSize,
            $poolTimeout,
            $dryRun,
            parallel: true,
        );

        if (! empty($failedDetail)) {
            $this->info('  Retry details (sequential, timeout='.$retryTimeout.'s): '.count($failedDetail).' запросов...');
            [$retryDispatched, $retryNoImages, $stillFailedDetail] = $this->fetchDetailsBatch(
                $failedDetail,
                1,
                $retryTimeout,
                $dryRun,
                parallel: false,
            );
            $dispatched += $retryDispatched;
            $noImages += $retryNoImages;
            $failedDetail = $stillFailedDetail;
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('      Поиск медиа завершён');
        $this->info('═══════════════════════════════════════');
        $this->line("  Всего без медиа:         {$total}");
        $this->line("  Найдено и отправлено:    {$dispatched}");
        $this->line("  Без изображений:         {$noImages}");
        $this->line('  Не найдено в API:        '.count($notMatched));
        $this->line('  HTTP-ошибки (details):   '.count($failedDetail));
        $this->line('  HTTP-ошибки (search):    '.count($failedSearch));
        if (! $dryRun && $dispatched > 0) {
            $this->line('  Очередь:                 catalog-media');
        }
        $this->info('═══════════════════════════════════════');

        return self::SUCCESS;
    }

    /**
     * Поиск по search endpoint. Возвращает [найденные, упавшие_по_http, несовпавшие_по_code_sku].
     * В найденные попадают только те, где payload содержит запись с совпадением по code или sku.
     */
    private function searchBatch(array $searchTerms, int $poolSize, int $timeout, bool $parallel): array
    {
        $remoteIds = [];
        $failed = [];
        $notMatched = [];

        $chunks = array_chunk($searchTerms, max(1, $poolSize), true);

        foreach ($chunks as $chunk) {
            $responses = $this->pooledGet($chunk, 'search_', function () {
                return $this->baseUrl;
            }, function ($info) {
                return [
                    'search' => $info['term'],
                    'force_flat' => 'true',
                    'search_availability' => 'any',
                ];
            }, $timeout, $parallel);

            foreach ($chunk as $productId => $info) {
                $response = $responses["search_{$productId}"] ?? null;

                if (! $response || ($response instanceof \Throwable) || ! $response->successful()) {
                    $failed[$productId] = $info;

                    continue;
                }

                $payload = $response->json('payload', []);
                if (empty($payload)) {
                    $notMatched[$productId] = $info;

                    continue;
                }

                $match = $this->matchByCodeOrBarcode($payload, $info['product'], $info['barcodes'] ?? []);
                if (! $match) {
                    $notMatched[$productId] = $info;

                    continue;
                }

                $remoteIds[$productId] = [
                    'remoteId' => $match['id'],
                    'product' => $info['product'],
                    'term' => $info['term'],
                ];
            }
        }

        return [$remoteIds, $failed, $notMatched];
    }

    /**
     * Запрос деталей. Возвращает [отправлено, без_картинок, упавшие_по_http].
     */
    private function fetchDetailsBatch(array $remoteIds, int $poolSize, int $timeout, bool $dryRun, bool $parallel): array
    {
        $dispatched = 0;
        $noImages = 0;
        $failed = [];

        $chunks = array_chunk($remoteIds, max(1, $poolSize), true);

        foreach ($chunks as $chunk) {
            $responses = $this->pooledGet($chunk, 'detail_', function ($info) {
                return "{$this->baseUrl}/{$info['remoteId']}";
            }, fn () => [], $timeout, $parallel);

            foreach ($chunk as $productId => $info) {
                $response = $responses["detail_{$productId}"] ?? null;

                if (! $response || ($response instanceof \Throwable) || ! $response->successful()) {
                    $failed[$productId] = $info;

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
                    $this->line("  ✓ [{$info['term']}] → ID {$info['remoteId']}: главная + ".count($additionalImages).' доп.');
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

        return [$dispatched, $noImages, $failed];
    }

    /**
     * Унифицированный вызов: параллельно через Http::pool или последовательно с обработкой исключений.
     * $urlResolver(info)->string URL, $queryResolver(info)->array query.
     */
    private function pooledGet(array $chunk, string $prefix, callable $urlResolver, callable $queryResolver, int $timeout, bool $parallel): array
    {
        if ($parallel && count($chunk) > 1) {
            try {
                return Http::pool(function ($pool) use ($chunk, $prefix, $urlResolver, $queryResolver, $timeout) {
                    foreach ($chunk as $productId => $info) {
                        $req = $pool->as($prefix.$productId)
                            ->withToken($this->token)
                            ->timeout($timeout)
                            ->connectTimeout(10);

                        $url = $urlResolver($info);
                        $query = $queryResolver($info);

                        empty($query) ? $req->get($url) : $req->get($url, $query);
                    }
                });
            } catch (\Throwable $e) {
                // если сам pool упал — даём шанс последовательному проходу
                return [];
            }
        }

        $responses = [];
        foreach ($chunk as $productId => $info) {
            try {
                $url = $urlResolver($info);
                $query = $queryResolver($info);

                $responses[$prefix.$productId] = Http::withToken($this->token)
                    ->timeout($timeout)
                    ->connectTimeout(10)
                    ->get($url, $query);
            } catch (\Throwable $e) {
                $responses[$prefix.$productId] = $e;
            }
        }

        return $responses;
    }

    /**
     * В списке результатов поиска ищем запись, совпадающую с нашим товаром по code или штрихкоду.
     * Приоритет — строгий матч по code. Если не нашлось — пробуем по любому из штрихкодов.
     * Артикул (sku/article) не используется намеренно: он может повторяться у разных товаров.
     */
    private function matchByCodeOrBarcode(array $payload, Product $product, array $barcodes): ?array
    {
        $code = (string) ($product->code ?? '');

        if ($code !== '') {
            foreach ($payload as $row) {
                if ((string) ($row['code'] ?? '') === $code) {
                    return $row;
                }
            }
        }

        $barcodeSet = array_flip(array_map('strval', $barcodes));
        if (! empty($barcodeSet)) {
            foreach ($payload as $row) {
                $rowBarcodes = [];
                if (! empty($row['barcode'])) {
                    $rowBarcodes[] = (string) $row['barcode'];
                }
                if (! empty($row['barcodes']) && is_array($row['barcodes'])) {
                    foreach ($row['barcodes'] as $b) {
                        $rowBarcodes[] = is_array($b) ? (string) ($b['barcode'] ?? '') : (string) $b;
                    }
                }

                foreach ($rowBarcodes as $rb) {
                    if ($rb !== '' && isset($barcodeSet[$rb])) {
                        return $row;
                    }
                }
            }
        }

        return null;
    }
}
