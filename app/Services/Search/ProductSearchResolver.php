<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Support\Search\HybridSearchOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Разрешение поискового запроса в упорядоченный по релевантности набор id товаров.
 *
 * Поиск на витрине двухслойный: Meilisearch отвечает за релевантность (опечатки,
 * синонимы, семантика), а фильтры / фасеты / сортировки каталога работают поверх
 * полученного набора обычным SQL. Здесь — первый слой.
 */
class ProductSearchResolver
{
    /**
     * Потолок выдачи Meilisearch, которую дальше фильтрует каталог.
     *
     * 1000 id — это 50 страниц по 20 товаров: глубже в поиске не ходят,
     * а фильтры позволяют сузить выборку вместо листания.
     */
    public const MAX_IDS = 1000;

    /** Время жизни кеша набора id (сек). */
    private const CACHE_TTL = 60;

    public function __construct(private readonly ExactProductMatcher $exactMatcher) {}

    /**
     * Набор id товаров по запросу, в порядке релевантности.
     *
     * @return array{ids: array<int, int>, exact: bool} exact — сработало точное
     *                                                  совпадение по артикулу / коду 1С / штрихкоду
     */
    public function resolve(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['ids' => [], 'exact' => false];
        }

        // Точное совпадение по sku/code/barcode — отдаём ТОЛЬКО товары с идентичным
        // кодом (обычно один, изредка несколько) и не трогаем Meilisearch, иначе он
        // подмешает фаззи-соседей.
        $exactMatches = $this->exactMatcher->matchAll($query);
        if ($exactMatches->isNotEmpty()) {
            return [
                'ids' => $exactMatches->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'exact' => true,
            ];
        }

        $ids = Cache::remember(
            'search:product-ids:'.md5(mb_strtolower($query)),
            self::CACHE_TTL,
            fn () => $this->searchIds($query),
        );

        return ['ids' => $ids, 'exact' => false];
    }

    /**
     * Keyword-first: сначала чистый полнотекстовый поиск, чтобы точные совпадения
     * не тонули среди семантических «соседей». Семантику (hybrid) подмешиваем
     * повтором запроса только при слабой выдаче.
     *
     * @return array<int, int>
     */
    private function searchIds(string $query): array
    {
        try {
            $ids = $this->keys($query, semantic: false);

            if (HybridSearchOptions::shouldFallback(count($ids))) {
                $semantic = $this->keys($query, semantic: true);

                if (count($semantic) > count($ids)) {
                    $ids = $semantic;
                }
            }

            return $ids;
        } catch (\Throwable $e) {
            Log::warning('ProductSearchResolver: Scout запрос упал', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, int>
     */
    private function keys(string $query, bool $semantic): array
    {
        $builder = Product::search($query);

        if ($semantic && $options = HybridSearchOptions::forProducts()) {
            $builder->options($options);
        }

        return $builder->take(self::MAX_IDS)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
