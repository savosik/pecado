<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Scopes\HiddenScope;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchSexOptIdsViaApi extends Command
{
    protected $signature = 'catalog:fetch-sex-opt-ids-via-api
        {--token= : Bearer-токен sex-opt API (иначе из config services.sex_opt.api_token / env SEX_OPT_API_TOKEN)}
        {--with-stock-warehouse= : Имя склада — берём только товары с остатком >0 на нём}
        {--limit=0 : Ограничение количества обрабатываемых товаров (0 = без ограничений)}
        {--order=asc : Порядок обхода по id (asc|desc) — desc сначала новые товары}
        {--rate-ms=150 : Задержка между запросами, мс}
        {--dry-run : Показать что будет обновлено, без записи}';

    protected $description = 'Дозаполнение sex_opt_id через API sex-opt (search по code/barcode/sku) — для товаров без sex_opt_id';

    private const ENDPOINT = 'https://backend.sex-opt.ru/api/v3/shop/products';

    public function handle(): int
    {
        $token = $this->option('token') ?: config('services.sex_opt.api_token');
        if (! $token) {
            $this->error('Не задан токен (--token= или SEX_OPT_API_TOKEN в .env / services.sex_opt.api_token)');

            return self::FAILURE;
        }

        $query = Product::query()
            ->withoutGlobalScope(HiddenScope::class)
            ->whereNull('sex_opt_id');

        $whName = $this->option('with-stock-warehouse');
        if ($whName) {
            $warehouse = Warehouse::where('name', $whName)->first();
            if (! $warehouse) {
                $this->error("Склад «{$whName}» не найден");

                return self::FAILURE;
            }
            $query->whereHas('warehouses', function ($q) use ($warehouse) {
                $q->where('warehouses.id', $warehouse->id)->where('product_warehouse.quantity', '>', 0);
            });
            $this->line("Фильтр: остаток > 0 на «{$whName}» (id={$warehouse->id})");
        }

        $order = strtolower((string) $this->option('order')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy('id', $order);

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->with('barcodes:id,product_id,barcode')
            ->get(['id', 'name', 'sku', 'code', 'external_id', 'barcode']);
        $this->info('К обработке: '.$products->count());
        if ($products->isEmpty()) {
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rateMs = max(0, (int) $this->option('rate-ms'));

        $stats = ['matched' => 0, 'updated' => 0, 'no_match' => 0, 'ambiguous' => 0, 'errors' => 0];
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $product) {
            $bar->advance();

            // Только надёжные ключи: 1С code и штрихкоды (12-13 цифр).
            // sku/article намеренно не используется — у sex-opt он часто пересекается между разными товарами.
            $barcodes = array_values(array_filter(
                array_merge(
                    [$product->barcode],
                    $product->barcodes->pluck('barcode')->all(),
                ),
                fn ($b) => is_string($b) && preg_match('/^\d{12,14}$/', $b),
            ));

            $candidates = array_values(array_unique(array_filter(array_merge(
                [$product->code],
                $barcodes,
            ))));

            $found = null;
            $matchVia = null;

            foreach ($candidates as $term) {
                try {
                    $hit = $this->searchSingleStrict($token, $term, $product);
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->newLine();
                    $this->warn("Ошибка для #{$product->id} term={$term}: ".$e->getMessage());
                    if ($rateMs > 0) {
                        usleep($rateMs * 1000);
                    }

                    continue;
                }
                if ($rateMs > 0) {
                    usleep($rateMs * 1000);
                }
                if ($hit) {
                    $found = $hit;
                    $matchVia = $term;
                    break;
                }
            }

            if (! $found) {
                $stats['no_match']++;

                continue;
            }

            $stats['matched']++;
            if (! $dryRun) {
                $product->sex_opt_id = (string) $found['id'];
                $product->saveQuietly();
            }
            $stats['updated']++;
        }

        $bar->finish();
        $this->newLine();

        $this->info('Найдено и обновлено: '.$stats['updated']);
        $this->line('Не найдено: '.$stats['no_match']);
        $this->line('Неоднозначно (>1 совпадения, пропущено): '.$stats['ambiguous']);
        $this->line('Ошибок запросов: '.$stats['errors']);
        if ($dryRun) {
            $this->warn('DRY RUN — изменения не применялись');
        }

        return self::SUCCESS;
    }

    /**
     * Возвращает payload-элемент только если он строго соответствует товару
     * (article=sku || code=code || barcode совпадает). Иначе null.
     *
     * @return array<string, mixed>|null
     */
    private function searchSingleStrict(string $token, string $term, Product $product): ?array
    {
        $resp = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 200)
            ->get(self::ENDPOINT, [
                'search' => $term,
                'force_flat' => 'true',
                'search_availability' => 'any',
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('HTTP '.$resp->status());
        }

        $payload = $resp->json('payload') ?? [];
        if (empty($payload)) {
            return null;
        }

        // Сравниваем строго по 1С code или barcode. article (sku) намеренно игнорируем —
        // у sex-opt он часто переиспользуется между товарами разных кодов.
        $ourBarcodes = collect([$product->barcode])
            ->merge($product->relationLoaded('barcodes') ? $product->barcodes->pluck('barcode') : [])
            ->filter(fn ($b) => is_string($b) && $b !== '')
            ->unique()
            ->all();

        $strict = [];
        foreach ($payload as $item) {
            $code = (string) ($item['code'] ?? '');
            $barcode = (string) ($item['barcode'] ?? '');

            $codeMatch = $product->code !== null && $product->code !== '' && $code === (string) $product->code;
            $barcodeMatch = $barcode !== '' && in_array($barcode, $ourBarcodes, true);

            if ($codeMatch || $barcodeMatch) {
                $strict[] = $item;
            }
        }

        if (count($strict) === 1) {
            return $strict[0];
        }

        return null;
    }
}
