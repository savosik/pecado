<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportCertificates extends Command
{
    protected $signature = 'catalog:import-certificates
        {--url= : URL эндпоинта экспорта сертификатов (default: /623)}
        {--links-url= : URL эндпоинта связей товар↔сертификат (default: /660)}
        {--skip-files : Импортировать только метаданные, без скачивания файлов}
        {--skip-links : Пропустить этап привязки к товарам}';

    protected $description = 'Импорт сертификатов и их привязок к товарам из JSON по URL';

    public function handle(): int
    {
        $url = $this->option('url')
            ?: 'https://customers.sex-opt.ru/api/public/export/623?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp';

        $linksUrl = $this->option('links-url')
            ?: 'https://customers.sex-opt.ru/api/public/export/660?auth_token=kqQKCZA73oORObUK3ApLy7xKJ7FYnYajFRekGsqp';

        $imported = $this->importCertificates($url);
        if ($imported === null) {
            return self::FAILURE;
        }

        if (! $this->option('skip-links')) {
            $this->importLinks($linksUrl);
        }

        return self::SUCCESS;
    }

    private function importCertificates(string $url): ?int
    {
        $this->info("Загрузка сертификатов: {$url}");

        $response = Http::timeout(120)->get($url);
        if (! $response->successful()) {
            $this->error("Ошибка загрузки сертификатов. HTTP {$response->status()}");

            return null;
        }

        $items = $response->json();
        if (! is_array($items)) {
            $this->error('Ошибка парсинга JSON сертификатов');

            return null;
        }

        $total = count($items);
        $this->info("Найдено сертификатов: {$total}");

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->start();

        $skipFiles = $this->option('skip-files');
        $imported = 0;
        $filesAdded = 0;

        foreach ($items as $item) {
            $uid = $item['certificate_uid'] ?? null;
            $name = $item['certificate_name'] ?? null;
            $type = $item['certificate_type'] ?? null;
            $issuedAtRaw = $item['certificate_issued_at'] ?? null;
            $files = $item['certificate_files'] ?? [];

            if (! $uid || ! $name) {
                $bar->advance();

                continue;
            }

            $bar->setMessage(mb_substr($name, 0, 50));

            try {
                $issuedAt = $this->parseDate($issuedAtRaw);

                $cert = Certificate::updateOrCreate(
                    ['external_id' => $uid],
                    [
                        'name' => $name,
                        'type' => $type ?: 'Сертификат',
                        'issued_at' => $issuedAt,
                    ],
                );

                if (! $skipFiles && is_array($files)) {
                    $filesAdded += $this->syncFiles($cert, $files);
                }

                $imported++;
            } catch (\Throwable $e) {
                Log::error("Ошибка импорта сертификата {$uid}: ".$e->getMessage());
                $this->newLine();
                $this->warn("  Ошибка {$uid}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Импортировано сертификатов: {$imported}. Добавлено файлов: {$filesAdded}.");

        return $imported;
    }

    private function syncFiles(Certificate $cert, array $urls): int
    {
        $added = 0;
        $existing = $cert->getMedia('files')
            ->pluck('custom_properties.source_url')
            ->filter()
            ->all();

        foreach ($urls as $fileUrl) {
            if (! is_string($fileUrl) || $fileUrl === '') {
                continue;
            }
            if (in_array($fileUrl, $existing, true)) {
                continue;
            }

            try {
                $cert->addMediaFromUrl($fileUrl)
                    ->withCustomProperties(['source_url' => $fileUrl])
                    ->toMediaCollection('files');
                $added++;
            } catch (\Throwable $e) {
                Log::warning("Не удалось скачать файл {$fileUrl} для сертификата {$cert->external_id}: ".$e->getMessage());
            }
        }

        return $added;
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }
        try {
            return Carbon::createFromFormat('d.m.Y', $raw)->startOfDay();
        } catch (\Throwable) {
            try {
                return Carbon::parse($raw);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function importLinks(string $url): void
    {
        $this->info("Загрузка связей товар↔сертификат: {$url}");

        $response = Http::timeout(300)->get($url);
        if (! $response->successful()) {
            $this->error("Ошибка загрузки связей. HTTP {$response->status()}");

            return;
        }

        $items = $response->json();
        if (! is_array($items)) {
            $this->error('Ошибка парсинга JSON связей');

            return;
        }

        $certIdByUid = Certificate::query()->pluck('id', 'external_id')->all();

        $withCerts = array_filter($items, fn ($i) => ! empty($i['certificates']));
        $total = count($withCerts);
        $this->info("Товаров с сертификатами: {$total}");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        $linked = 0;
        $missingProducts = 0;
        $missingCerts = 0;

        foreach ($withCerts as $item) {
            $productUid = $item['uid'] ?? null;
            $certUids = $item['certificates'] ?? [];

            if (! $productUid) {
                $bar->advance();

                continue;
            }

            $product = Product::where('external_id', $productUid)->first();
            if (! $product) {
                $missingProducts++;
                $bar->advance();

                continue;
            }

            $certIds = [];
            foreach ($certUids as $certUid) {
                if (isset($certIdByUid[$certUid])) {
                    $certIds[] = $certIdByUid[$certUid];
                } else {
                    $missingCerts++;
                }
            }

            if (! empty($certIds)) {
                $product->certificates()->syncWithoutDetaching($certIds);
                $linked++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Привязок обновлено: {$linked} товаров.");
        if ($missingProducts > 0) {
            $this->warn("Товаров не найдено по external_id: {$missingProducts}");
        }
        if ($missingCerts > 0) {
            $this->warn("Ссылок на отсутствующие сертификаты: {$missingCerts}");
        }
    }
}
