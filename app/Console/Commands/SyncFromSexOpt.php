<?php

namespace App\Console\Commands;

use App\Jobs\SyncCertificateFileJob;
use App\Jobs\SyncProductImagesJob;
use App\Jobs\SyncProductVideosJob;
use App\Models\Certificate;
use App\Models\Product;
use App\Models\Scopes\HiddenScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncFromSexOpt extends Command
{
    protected $signature = 'catalog:sync-from-sex-opt
        {--description : Обновить description (markdown) и description_html}
        {--images : Перезалить изображения (main + additional) через очередь}
        {--videos : Перезалить видео через очередь}
        {--certificates : Синхронизировать сертификаты (создать/привязать + загрузить файлы)}
        {--all : Включает description+images+videos+certificates}
        {--token= : Bearer-токен sex-opt API (иначе SEX_OPT_API_TOKEN из env)}
        {--product= : Обработать только конкретный товар по нашему products.id}
        {--limit=0 : Ограничение количества товаров (0 = без ограничений)}
        {--order=asc : Порядок обхода по products.id (asc|desc)}
        {--rate-ms=300 : Задержка между запросами к API, мс}
        {--dry-run : Не записывать в БД, не диспатчить jobs}';

    protected $description = 'Синхронизация полей товара из API sex-opt по sex_opt_id (description/images/videos/certificates)';

    private const ENDPOINT_BASE = 'https://backend.sex-opt.ru/api/v3/shop/products';

    public function handle(): int
    {
        $token = $this->option('token') ?: config('services.sex_opt.api_token');
        if (! $token) {
            $this->error('Не задан токен (--token= или SEX_OPT_API_TOKEN в .env / services.sex_opt.api_token)');

            return self::FAILURE;
        }

        $flags = $this->resolveFlags();
        if (! array_filter($flags)) {
            $this->error('Не указано что синхронизировать. Используйте --description / --images / --videos / --certificates / --all');

            return self::FAILURE;
        }

        $query = Product::query()
            ->withoutGlobalScope(HiddenScope::class)
            ->whereNotNull('sex_opt_id');

        if ($productId = $this->option('product')) {
            $query->where('id', (int) $productId);
        }

        $order = strtolower((string) $this->option('order')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy('id', $order);

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get(['id', 'sex_opt_id', 'code', 'name']);
        $total = $products->count();
        $this->info("К обработке: {$total}");
        $this->line('Включены: '.implode(', ', array_keys(array_filter($flags))));

        if ($total === 0) {
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rateMs = max(0, (int) $this->option('rate-ms'));

        $stats = [
            'fetched' => 0,
            'http_errors' => 0,
            'descriptions_updated' => 0,
            'images_dispatched' => 0,
            'videos_dispatched' => 0,
            'cert_created' => 0,
            'cert_linked' => 0,
            'cert_files_dispatched' => 0,
        ];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($products as $product) {
            $bar->advance();

            try {
                $payload = $this->fetchDetail($token, (string) $product->sex_opt_id);
            } catch (\Throwable $e) {
                $stats['http_errors']++;
                $this->newLine();
                $this->warn("HTTP-ошибка для #{$product->id} (sex_opt_id={$product->sex_opt_id}): ".$e->getMessage());
                $this->throttle($rateMs);

                continue;
            }

            $stats['fetched']++;

            if ($flags['description']) {
                $this->applyDescription($product, $payload, $dryRun, $stats);
            }
            if ($flags['images']) {
                $this->dispatchImages($product, $payload, $dryRun, $stats);
            }
            if ($flags['videos']) {
                $this->dispatchVideos($product, $payload, $dryRun, $stats);
            }
            if ($flags['certificates']) {
                $this->applyCertificates($product, $payload, $dryRun, $stats);
            }

            $this->throttle($rateMs);
        }

        $bar->finish();
        $this->newLine();

        $this->info('Готово.');
        $this->line('Получено payload-ов:        '.$stats['fetched']);
        $this->line('HTTP-ошибок:                '.$stats['http_errors']);
        if ($flags['description']) {
            $this->line('Описаний обновлено:         '.$stats['descriptions_updated']);
        }
        if ($flags['images']) {
            $this->line('Jobs изображений:           '.$stats['images_dispatched']);
        }
        if ($flags['videos']) {
            $this->line('Jobs видео:                 '.$stats['videos_dispatched']);
        }
        if ($flags['certificates']) {
            $this->line('Сертификатов создано:       '.$stats['cert_created']);
            $this->line('Привязок к товарам:         '.$stats['cert_linked']);
            $this->line('Jobs файлов сертификатов:   '.$stats['cert_files_dispatched']);
        }
        if ($dryRun) {
            $this->warn('DRY RUN — никаких изменений и диспатчей не было');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, bool>
     */
    private function resolveFlags(): array
    {
        $all = (bool) $this->option('all');

        return [
            'description' => $all || (bool) $this->option('description'),
            'images' => $all || (bool) $this->option('images'),
            'videos' => $all || (bool) $this->option('videos'),
            'certificates' => $all || (bool) $this->option('certificates'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDetail(string $token, string $sexOptId): array
    {
        $resp = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->get(self::ENDPOINT_BASE."/{$sexOptId}");

        if (! $resp->successful()) {
            throw new \RuntimeException('HTTP '.$resp->status());
        }

        $payload = $resp->json('payload');
        if (! is_array($payload)) {
            throw new \RuntimeException('Не распарсился payload');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $stats
     */
    private function applyDescription(Product $product, array $payload, bool $dryRun, array &$stats): void
    {
        $description = $payload['description'] ?? null;
        $html = $payload['description_parsedown'] ?? null;
        if ($description === null && $html === null) {
            return;
        }

        $changed = false;
        if (is_string($description) && $description !== '' && $description !== $product->description) {
            if (! $dryRun) {
                $product->description = $description;
            }
            $changed = true;
        }
        if (is_string($html) && $html !== '' && $html !== $product->description_html) {
            if (! $dryRun) {
                $product->description_html = $html;
            }
            $changed = true;
        }

        if ($changed) {
            if (! $dryRun) {
                $product->saveQuietly();
            }
            $stats['descriptions_updated']++;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $stats
     */
    private function dispatchImages(Product $product, array $payload, bool $dryRun, array &$stats): void
    {
        $images = $payload['images'] ?? [];
        if (! is_array($images) || empty($images)) {
            return;
        }

        $urls = array_values(array_filter(array_map(
            fn ($img) => is_array($img) ? ($img['url'] ?? null) : null,
            $images,
        )));
        if (empty($urls)) {
            return;
        }

        $main = array_shift($urls);
        if (! $dryRun) {
            SyncProductImagesJob::dispatch($product->id, $main, $urls);
        }
        $stats['images_dispatched']++;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $stats
     */
    private function dispatchVideos(Product $product, array $payload, bool $dryRun, array &$stats): void
    {
        $videos = $payload['videos'] ?? [];
        if (! is_array($videos) || empty($videos)) {
            return;
        }

        $urls = array_values(array_filter(array_map(
            fn ($v) => is_array($v) ? ($v['link'] ?? $v['url'] ?? null) : null,
            $videos,
        )));
        if (empty($urls)) {
            return;
        }

        if (! $dryRun) {
            SyncProductVideosJob::dispatch($product->id, $urls);
        }
        $stats['videos_dispatched']++;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $stats
     */
    private function applyCertificates(Product $product, array $payload, bool $dryRun, array &$stats): void
    {
        $certs = $payload['certificates'] ?? [];
        if (! is_array($certs) || empty($certs)) {
            return;
        }

        $certIds = [];
        foreach ($certs as $cert) {
            if (! is_array($cert) || empty($cert['id'])) {
                continue;
            }

            $sexOptId = (string) $cert['id'];
            $name = (string) ($cert['name'] ?? "Сертификат #{$sexOptId}");
            $type = (string) ($cert['type'] ?? 'Сертификат');

            $certificate = Certificate::query()->where('sex_opt_id', $sexOptId)->first();
            if (! $certificate) {
                if ($dryRun) {
                    $stats['cert_created']++;

                    continue;
                }
                $certificate = Certificate::create([
                    'sex_opt_id' => $sexOptId,
                    'name' => $name,
                    'type' => $type ?: 'Сертификат',
                ]);
                $stats['cert_created']++;
            }

            $certIds[] = $certificate->id;

            $files = $cert['files'] ?? [];
            if (is_array($files)) {
                foreach ($files as $file) {
                    $uid = is_array($file) ? ($file['uid'] ?? null) : null;
                    if (! $uid) {
                        continue;
                    }
                    if (! $dryRun) {
                        SyncCertificateFileJob::dispatch($certificate->id, (string) $uid);
                    }
                    $stats['cert_files_dispatched']++;
                }
            }
        }

        if (! empty($certIds) && ! $dryRun) {
            $product->certificates()->syncWithoutDetaching($certIds);
        }
        $stats['cert_linked'] += count($certIds);
    }

    private function throttle(int $rateMs): void
    {
        if ($rateMs > 0) {
            usleep($rateMs * 1000);
        }
    }
}
