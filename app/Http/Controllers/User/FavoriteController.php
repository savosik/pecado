<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Product\ProductQueryService;
use App\Support\Search\QueryRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FavoriteController extends Controller
{
    private const SORT_OPTIONS = [
        'added_desc' => 'По дате добавления (сначала новые)',
        'added_asc' => 'По дате добавления (сначала старые)',
        'price_asc' => 'По цене (сначала дешёвые)',
        'price_desc' => 'По цене (сначала дорогие)',
        'name_asc' => 'По названию (А → Я)',
        'name_desc' => 'По названию (Я → А)',
        'stock_desc' => 'По остаткам (сначала в наличии)',
    ];

    private const AVAILABILITY_OPTIONS = [
        'in_stock' => 'В наличии',
        'preorder' => 'Предзаказ',
        'out_of_stock' => 'Нет в наличии',
    ];

    /**
     * Страница «Избранное» — Inertia-рендер с поиском, фильтрами и сортировкой.
     * GET /favorites
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $search = trim((string) $request->input('search', ''));
        $sort = (string) $request->input('sort', 'added_desc');
        if (! array_key_exists($sort, self::SORT_OPTIONS)) {
            $sort = 'added_desc';
        }
        $availability = $request->input('availability');
        if (! is_string($availability) || ! array_key_exists($availability, self::AVAILABILITY_OPTIONS)) {
            $availability = null;
        }
        $brandIds = array_values(array_filter(array_map('intval', (array) $request->input('brand_ids', []))));
        $categoryIds = array_values(array_filter(array_map('intval', (array) $request->input('category_ids', []))));

        // ВАЖНО: select('products.*') ДОЛЖЕН идти ДО withRegionStockSums(),
        // иначе addSelect sub-selects для stock будут перезатёрты.
        $query = Product::query()
            ->join('favorites', 'products.id', '=', 'favorites.product_id')
            ->where('favorites.user_id', $user->id)
            ->select('products.*')
            ->with(array_merge(ProductQueryService::productEagerLoads(), ['category']));

        ProductQueryService::withRegionStockSums($query);

        $this->applySearch($query, $search);
        $this->applyAvailability($query, $availability);

        if (! empty($brandIds)) {
            $query->whereIn('products.brand_id', $brandIds);
        }
        if (! empty($categoryIds)) {
            $query->whereIn('products.category_id', $categoryIds);
        }

        $this->applySort($query, $sort);

        $paginated = $query->paginate(20)->withQueryString();
        $items = $paginated->items();
        $products = collect($items)->map(function ($product) {
            $row = ProductQueryService::productToArray($product);
            $row['category'] = $product->category_id !== null
                ? ['id' => (int) $product->category_id, 'name' => $product->category?->name ?? '']
                : null;

            return $row;
        })->toArray();

        $products = ProductQueryService::enrichProductsWithDiscounts($products);
        $products = ProductQueryService::convertProductsPrices($products);

        return Inertia::render('User/Favorites/Index', [
            'favorites' => [
                'data' => $products,
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'availability' => $availability,
                'brand_ids' => $brandIds,
                'category_ids' => $categoryIds,
            ],
            'facets' => [
                'brands' => $this->facetBrands($user->id),
                'categories' => $this->facetCategories($user->id),
            ],
            'sortOptions' => self::SORT_OPTIONS,
            'availabilityOptions' => self::AVAILABILITY_OPTIONS,
        ]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $type = QueryRouter::classify($search);
        $like = '%'.$search.'%';

        $query->where(function (Builder $q) use ($search, $type, $like) {
            $q->where('products.name', 'like', $like)
                ->orWhere('products.sku', 'like', $like)
                ->orWhere('products.code', 'like', $like)
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $like));

            if ($type === QueryRouter::TYPE_BARCODE) {
                $q->orWhereHas('barcodes', fn (Builder $bq) => $bq->where('barcode', $search));
            } else {
                $q->orWhereHas('barcodes', fn (Builder $bq) => $bq->where('barcode', 'like', $like));
            }
        });
    }

    private function applyAvailability(Builder $query, ?string $availability): void
    {
        if ($availability === null) {
            return;
        }

        $wh = ProductQueryService::getRegionWarehouseIds();
        $primaryIds = $wh['primary'];
        $preorderIds = $wh['preorder'];

        if ($availability === 'in_stock') {
            if (empty($primaryIds)) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereExists(function ($q) use ($primaryIds) {
                $q->select(DB::raw(1))
                    ->from('product_warehouse')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('warehouse_id', $primaryIds)
                    ->where('quantity', '>', 0);
            });
        } elseif ($availability === 'preorder') {
            if (empty($preorderIds)) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereExists(function ($q) use ($preorderIds) {
                $q->select(DB::raw(1))
                    ->from('product_warehouse')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('warehouse_id', $preorderIds)
                    ->where('quantity', '>', 0);
            });
        } elseif ($availability === 'out_of_stock') {
            $allIds = array_merge($primaryIds, $preorderIds);
            if (empty($allIds)) {
                return;
            }
            $query->whereNotExists(function ($q) use ($allIds) {
                $q->select(DB::raw(1))
                    ->from('product_warehouse')
                    ->whereColumn('product_warehouse.product_id', 'products.id')
                    ->whereIn('warehouse_id', $allIds)
                    ->where('quantity', '>', 0);
            });
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'added_asc' => $query->orderBy('favorites.created_at')->orderBy('products.id'),
            'price_asc' => $query->orderBy('products.base_price')->orderByDesc('favorites.created_at'),
            'price_desc' => $query->orderByDesc('products.base_price')->orderByDesc('favorites.created_at'),
            'name_asc' => $query->orderBy('products.name')->orderByDesc('favorites.created_at'),
            'name_desc' => $query->orderByDesc('products.name')->orderByDesc('favorites.created_at'),
            'stock_desc' => $query->orderByRaw('(COALESCE(primary_stock, 0) + COALESCE(preorder_stock, 0)) DESC')
                ->orderByDesc('favorites.created_at'),
            default => $query->orderByDesc('favorites.created_at')->orderByDesc('products.id'),
        };
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function facetBrands(int $userId): array
    {
        return Brand::query()
            ->whereIn('id', function ($q) use ($userId) {
                $q->select('products.brand_id')
                    ->from('products')
                    ->join('favorites', 'favorites.product_id', '=', 'products.id')
                    ->where('favorites.user_id', $userId)
                    ->whereNotNull('products.brand_id');
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function facetCategories(int $userId): array
    {
        return Category::query()
            ->whereIn('id', function ($q) use ($userId) {
                $q->select('products.category_id')
                    ->from('products')
                    ->join('favorites', 'favorites.product_id', '=', 'products.id')
                    ->where('favorites.user_id', $userId)
                    ->whereNotNull('products.category_id');
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
            ->all();
    }

    /**
     * Получить список ID избранных товаров текущего пользователя.
     * GET /api/favorites/ids
     */
    public function ids(Request $request): JsonResponse
    {
        $productIds = $request->user()
            ->favorites()
            ->pluck('product_id')
            ->toArray();

        return response()->json([
            'product_ids' => $productIds,
        ]);
    }

    /**
     * Переключить товар в избранном (toggle).
     * POST /api/favorites/{product}/toggle
     *
     * Атомарный подход: сначала пробуем удалить, если ничего не удалено — создаём.
     * Защищает от дублей при двойном клике.
     */
    public function toggle(Request $request, Product $product): JsonResponse
    {
        $deleted = $request->user()
            ->favorites()
            ->where('product_id', $product->id)
            ->delete();

        if ($deleted) {
            return response()->json(['removed' => true]);
        }

        $request->user()->favorites()->firstOrCreate([
            'product_id' => $product->id,
        ]);

        return response()->json(['added' => true]);
    }
}
