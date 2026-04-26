<?php

namespace App\Services\Product;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Сервис подбора похожих товаров для детальной карточки.
 *
 * Использует тот же механизм, что и поиск в шапке: Scout (Meilisearch)
 * по названию текущего товара. Затем фильтрует результаты по наличию
 * (в наличии или предзаказ) и возвращает массив в формате каталога.
 *
 * Тяжёлая часть — обход Scout — кешируется на 30 минут списком ID,
 * чтобы не дёргать поисковый индекс на каждой загрузке карточки.
 */
class SimilarProductsService
{
    private const CACHE_TTL = 1800; // 30 минут

    private const LIMIT = 12;

    /**
     * Получить массив похожих товаров для переданного товара.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forProduct(Product $product): array
    {
        $candidateIds = $this->getCandidateIds($product);
        if (empty($candidateIds)) {
            return [];
        }

        // Загружаем модели с остатками региона текущего пользователя.
        // Кеш по ID-списку нельзя — остатки зависят от региона запроса.
        $query = Product::query()
            ->whereIn('products.id', $candidateIds)
            ->where('products.hidden', false)
            ->select('products.*')
            ->with(ProductQueryService::productEagerLoads());

        ProductQueryService::withRegionStockSums($query);

        $models = $query->get();

        // Сохраняем порядок Scout-выдачи.
        $orderMap = array_flip($candidateIds);
        $models = $models->sortBy(fn (Product $p) => $orderMap[$p->id] ?? PHP_INT_MAX)->values();

        // Только в наличии или предзаказ.
        $models = $models->filter(function (Product $p) {
            return ($p->primary_stock ?? 0) > 0 || ($p->preorder_stock ?? 0) > 0;
        })->values();

        if ($models->isEmpty()) {
            return [];
        }

        $array = $models
            ->map(fn (Product $p) => ProductQueryService::productToArray($p))
            ->values()
            ->toArray();

        $array = ProductQueryService::enrichProductsWithDiscounts($array);
        $array = ProductQueryService::convertProductsPrices($array);

        return $array;
    }

    /**
     * ID кандидатов от Scout по названию товара.
     * Кешируется на 30 минут — не зависит от региона/пользователя.
     *
     * @return array<int, int>
     */
    private function getCandidateIds(Product $product): array
    {
        $name = trim((string) $product->name);
        if ($name === '') {
            return [];
        }

        return Cache::remember(
            "similar_products:ids:{$product->id}",
            self::CACHE_TTL,
            function () use ($product, $name) {
                $hybrid = $this->getHybridSearchOptions();

                $ids = $this->scoutKeys($name, $hybrid);

                // Fallback: если гибридный поиск недоступен (например, на dev
                // нет embedder-а в Meilisearch), пробуем без опций.
                if ($ids === null && $hybrid !== null) {
                    $ids = $this->scoutKeys($name, null);
                }

                if ($ids === null) {
                    return [];
                }

                return array_values(array_filter(
                    array_map('intval', $ids),
                    fn (int $id) => $id > 0 && $id !== (int) $product->id
                ));
            }
        );
    }

    /**
     * Получить ID-ы товаров от Scout по запросу.
     * Возвращает null при ошибке (для fallback-сценария).
     *
     * @return array<int, int>|null
     */
    private function scoutKeys(string $name, ?array $options): ?array
    {
        try {
            $builder = Product::search($name);

            if ($options) {
                $builder->options($options);
            }

            return $builder->take(self::LIMIT + 1)->keys()->all();
        } catch (\Throwable $e) {
            Log::warning('SimilarProductsService: Scout запрос упал', [
                'query' => $name,
                'with_options' => $options !== null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getHybridSearchOptions(): ?array
    {
        if (! config('search.hybrid.enabled')) {
            return null;
        }

        return [
            'hybrid' => [
                'embedder' => config('search.hybrid.embedder'),
                'semanticRatio' => config('search.hybrid.semantic_ratio'),
            ],
        ];
    }
}
