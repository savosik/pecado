<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Services\Product\ProductQueryService;
use Illuminate\Support\Facades\Log;

/**
 * Точный матч товара по артикулу / коду 1С / штрихкоду.
 *
 * Используется как fast-path перед обращением к Meilisearch, чтобы
 * пользователь, вбивая полный SKU/штрихкод, гарантированно получал
 * именно этот товар первым (Meilisearch с typo tolerance ранжирует
 * соседние коды одинаково высоко).
 */
class ExactProductMatcher
{
    private const MIN_LEN = 4;

    private const MAX_LEN = 64;

    /**
     * Найти товар точным совпадением по sku/code/barcode/product_barcodes.barcode.
     *
     * Возвращает null, если запрос не похож на код (есть пробелы, слишком короткий/длинный)
     * или ничего не нашлось.
     */
    public function match(string $query): ?Product
    {
        if (! $this->isCodeLikeQuery($query)) {
            return null;
        }

        $q = trim($query);

        $builder = Product::query()
            ->select('products.*')
            ->where(function ($w) use ($q) {
                $w->where('products.sku', $q)
                    ->orWhere('products.code', $q)
                    ->orWhere('products.barcode', $q)
                    ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $q));
            })
            ->with(ProductQueryService::productEagerLoads())
            ->limit(2);

        ProductQueryService::withRegionStockSums($builder);

        $matches = $builder->get();

        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() > 1) {
            Log::warning('ExactProductMatcher: несколько товаров совпадают по точному коду', [
                'query' => $q,
                'product_ids' => $matches->pluck('id')->all(),
            ]);
        }

        return $matches->first();
    }

    /**
     * Эта же проверка без обращения к БД — для решения «стоит ли вообще пытаться».
     */
    public function isCodeLikeQuery(string $query): bool
    {
        $q = trim($query);
        if ($q === '' || str_contains($q, ' ')) {
            return false;
        }
        $len = mb_strlen($q);

        return $len >= self::MIN_LEN && $len <= self::MAX_LEN;
    }
}
