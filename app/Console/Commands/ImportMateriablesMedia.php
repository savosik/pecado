<?php

namespace App\Console\Commands;

use App\Jobs\DownloadProductMediaJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportMateriablesMedia extends Command
{
    protected $signature = 'catalog:import-materiables-media
        {--page=1 : Стартовая страница}
        {--max-pages=0 : Максимум страниц (0 = до конца)}
        {--missing-only : Только товары без главного изображения (по умолчанию включено)}
        {--replace : Перезагружать медиа всех товаров из выдачи поверх существующих}
        {--dry-run : Показать что будет загружено, без отправки в очередь}
        {--page-timeout=60 : HTTP-таймаут (сек) для запроса страницы}';

    protected $description = 'Импорт медиа товаров из media-сервиса sex-opt.ru (materiables/product, постранично)';

    private const CENSOR_TAG_ID = 6;

    private const CENSOR_TAG_NAME = 'Цензура';

    private const UTP_TAG_NAME = 'УТП';

    private const PICTURE_MATERIAL_TYPES = ['product_picture'];

    private const VIDEO_MATERIAL_TYPES = ['product_video', 'promo_video'];

    private const MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    public function handle(): int
    {
        $baseUrl = (string) config('services.media_sex_opt.base_url');
        $token = (string) config('services.media_sex_opt.token');

        if ($baseUrl === '' || $token === '') {
            $this->error('Не задан SEX_OPT_MEDIA_BASE_URL или SEX_OPT_MEDIA_TOKEN в .env');

            return self::FAILURE;
        }

        $startPage = max(1, (int) $this->option('page'));
        $maxPages = max(0, (int) $this->option('max-pages'));
        $pageTimeout = max(5, (int) $this->option('page-timeout'));
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');
        $missingOnly = ! $replace; // по умолчанию missing-only; --replace его выключает

        if ($replace && $this->option('missing-only')) {
            $this->warn('--replace и --missing-only взаимоисключающие; используем --replace');
        }

        $endpoint = rtrim($baseUrl, '/').'/api/v1/public/materiables/product';

        $this->info('Импорт медиа из materiables-сервиса');
        $this->line("  URL:           {$endpoint}");
        $this->line('  Режим:         '.($missingOnly ? 'missing-only' : 'replace (все из выдачи)'));
        $this->line('  Стартовая стр: '.$startPage);
        $this->line('  Лимит страниц: '.($maxPages === 0 ? '— (все)' : (string) $maxPages));
        if ($dryRun) {
            $this->warn('  DRY-RUN: задачи в очередь не отправляются');
        }
        $this->newLine();

        $missingMap = null;
        if ($missingOnly) {
            $missingMap = Product::whereDoesntHave('media', function ($q) {
                $q->where('collection_name', 'main');
            })
                ->whereNotNull('external_id')
                ->pluck('id', 'external_id')
                ->all();
            $this->info('Товаров без главного изображения: '.count($missingMap));
        }

        $pagesProcessed = 0;
        $totalSeen = 0;
        $dispatched = 0;
        $skippedHasMedia = 0;
        $skippedNoProduct = 0;
        $noMedia = 0;

        $page = $startPage;

        while (true) {
            if ($maxPages > 0 && $pagesProcessed >= $maxPages) {
                break;
            }

            $this->line("→ Страница {$page}...");

            try {
                $response = Http::withToken($token)
                    ->timeout($pageTimeout)
                    ->connectTimeout(15)
                    ->acceptJson()
                    ->retry(5, 3000, throw: false)
                    ->get($endpoint, ['page' => $page]);
            } catch (\Throwable $e) {
                $this->warn("  HTTP-ошибка на стр. {$page} после ретраев: {$e->getMessage()}");
                $this->warn('  Пропускаем страницу, продолжаем со следующей.');
                $page++;

                continue;
            }

            if (! $response->successful()) {
                $this->warn("  HTTP {$response->status()} на стр. {$page}; пропускаем");
                $page++;

                continue;
            }

            $payload = (array) $response->json('payload', []);
            $lastPage = $response->json('meta.last_page');

            if (empty($payload)) {
                $this->line('  Пусто. Завершаем.');
                break;
            }

            foreach ($payload as $item) {
                $totalSeen++;

                $uid = (string) ($item['uid'] ?? '');
                if ($uid === '') {
                    $skippedNoProduct++;

                    continue;
                }

                if ($missingMap !== null && ! isset($missingMap[$uid])) {
                    $skippedHasMedia++;

                    continue;
                }

                $product = Product::where('external_id', $uid)->first();

                if (! $product) {
                    $code = $this->extractInfoValue($item['extended_info'] ?? [], 'code');
                    if ($code !== '') {
                        $product = Product::where('code', $code)->first();
                    }
                }

                if (! $product) {
                    $skippedNoProduct++;

                    continue;
                }

                $itemData = $this->extractMediaUrls($item['materials'] ?? []);

                $hasAny = ! empty($itemData['image_main'])
                    || ! empty($itemData['additional_images'])
                    || ! empty($itemData['product_videos']);

                if (! $hasAny) {
                    $noMedia++;

                    continue;
                }

                if ($dryRun) {
                    $imgCount = (! empty($itemData['image_main']) ? 1 : 0) + count($itemData['additional_images']);
                    $vidCount = count($itemData['product_videos']);
                    $this->line("  ✓ {$product->code}: изображений {$imgCount}, видео {$vidCount}");
                } else {
                    DownloadProductMediaJob::dispatch($product->id, $itemData);
                }

                $dispatched++;
            }

            $pagesProcessed++;
            $page++;

            if (is_int($lastPage) && $page > $lastPage) {
                $this->line("  Достигнута последняя страница ({$lastPage}). Завершаем.");
                break;
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('      Импорт materiables завершён');
        $this->info('═══════════════════════════════════════');
        $this->line("  Страниц обработано:      {$pagesProcessed}");
        $this->line("  Всего записей:           {$totalSeen}");
        $this->line('  '.($dryRun ? 'К отправке (dry-run): ' : 'Отправлено в очередь:    ').$dispatched);
        if ($missingOnly) {
            $this->line("  Пропущено (есть медиа):  {$skippedHasMedia}");
        }
        $this->line("  Пропущено (нет товара):  {$skippedNoProduct}");
        $this->line("  Без медиа в материалах:  {$noMedia}");
        if (! $dryRun && $dispatched > 0) {
            $this->line('  Очередь:                 catalog-media');
        }
        $this->info('═══════════════════════════════════════');

        return self::SUCCESS;
    }

    /**
     * Из дерева materials собирает плоский набор URL для DownloadProductMediaJob.
     *
     * @param  array<int,array<string,mixed>>  $materials
     * @return array{image_main:string,additional_images:array<int,string>,product_videos:array<int,string>}
     */
    private function extractMediaUrls(array $materials): array
    {
        $images = [];
        $videos = [];

        foreach ($materials as $material) {
            $type = (string) ($material['type'] ?? '');
            $files = (array) ($material['files'] ?? []);
            $files = array_values(array_filter($files, fn ($f) => empty($f['is_preview'])));

            if (empty($files)) {
                continue;
            }

            if (in_array($type, self::VIDEO_MATERIAL_TYPES, true)) {
                $picked = $this->pickVideoFile($files);
                if ($picked !== null) {
                    $videos[] = $picked;
                }

                continue;
            }

            if (! in_array($type, self::PICTURE_MATERIAL_TYPES, true)) {
                continue;
            }

            $picked = $this->pickImageFile($files);
            if ($picked !== null) {
                $images[] = $picked;
            }
        }

        // УТП-картинки — кандидаты на главное; внутри: чистые > цензурные.
        usort($images, function (array $a, array $b) {
            if ($a['utp'] !== $b['utp']) {
                return $b['utp'] <=> $a['utp'];
            }

            return $a['censored'] <=> $b['censored'];
        });

        $urls = array_map(fn (array $i) => $i['url'], $images);

        return [
            'image_main' => $urls[0] ?? '',
            'additional_images' => array_slice($urls, 1),
            'product_videos' => $videos,
        ];
    }

    /**
     * Среди файлов одного material выбирает изображение и метаданные тегов
     * (УТП и Цензура), которые потом используются для сортировки картинок.
     *
     * @param  array<int,array<string,mixed>>  $files
     * @return array{url:string,utp:bool,censored:bool}|null
     */
    private function pickImageFile(array $files): ?array
    {
        $clean = null;
        $censored = null;

        foreach ($files as $file) {
            $url = (string) ($file['file'] ?? '');
            if ($url === '' || ! $this->isImageFile($file)) {
                continue;
            }

            $entry = [
                'url' => $url,
                'utp' => $this->fileHasUtpTag($file),
                'censored' => $this->fileHasCensorTag($file),
            ];

            if ($entry['censored']) {
                $censored ??= $entry;
            } else {
                $clean ??= $entry;
            }
        }

        return $clean ?? $censored;
    }

    /**
     * Берёт первый валидный URL видеофайла размером не больше MAX_VIDEO_BYTES.
     *
     * @param  array<int,array<string,mixed>>  $files
     */
    private function pickVideoFile(array $files): ?string
    {
        foreach ($files as $file) {
            $url = (string) ($file['file'] ?? '');
            if ($url === '' || ! $this->isVideoFile($file)) {
                continue;
            }

            $size = (int) ($this->fileMetaValue($file, 'size') ?? 0);
            if ($size > 0 && $size > self::MAX_VIDEO_BYTES) {
                continue;
            }

            return $url;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function isImageFile(array $file): bool
    {
        if (($file['type'] ?? null) === 'common_image') {
            return true;
        }

        $mime = $this->fileMetaValue($file, 'mime_type');
        if (is_string($mime) && str_starts_with($mime, 'image/')) {
            return true;
        }

        return $this->fileMetaValue($file, 'type') === 'image';
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function isVideoFile(array $file): bool
    {
        if ($this->fileMetaValue($file, 'type') === 'video') {
            return true;
        }

        $mime = $this->fileMetaValue($file, 'mime_type');

        return is_string($mime) && str_starts_with($mime, 'video/');
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function fileMetaValue(array $file, string $code): mixed
    {
        foreach ((array) ($file['meta'] ?? []) as $row) {
            if (($row['code'] ?? null) === $code) {
                return $row['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function fileHasCensorTag(array $file): bool
    {
        foreach ((array) ($file['tags'] ?? []) as $tag) {
            if ((int) ($tag['id'] ?? 0) === self::CENSOR_TAG_ID) {
                return true;
            }
            if (($tag['name'] ?? null) === self::CENSOR_TAG_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function fileHasUtpTag(array $file): bool
    {
        foreach ((array) ($file['tags'] ?? []) as $tag) {
            if (($tag['name'] ?? null) === self::UTP_TAG_NAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $extendedInfo
     */
    private function extractInfoValue(array $extendedInfo, string $key): string
    {
        foreach ($extendedInfo as $row) {
            if (($row['key'] ?? null) === $key) {
                return (string) ($row['value'] ?? '');
            }
        }

        return '';
    }
}
