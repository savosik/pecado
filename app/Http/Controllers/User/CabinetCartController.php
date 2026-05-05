<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Region;
use App\Support\Search\FuzzyProductMatcher;
use App\Support\Search\QueryRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CabinetCartController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService,
    ) {}

    private const SORT_FIELDS = ['updated_at', 'created_at', 'name', 'total_amount', 'items_count'];

    private const TOTAL_AMOUNT_SUBQUERY = '(SELECT CAST(COALESCE(SUM(quantity * COALESCE(price, 0)), 0) AS REAL) FROM cart_items WHERE cart_items.cart_id = carts.id)';

    private const ITEMS_COUNT_SUBQUERY = '(SELECT COUNT(*) FROM cart_items WHERE cart_items.cart_id = carts.id)';

    /**
     * List all user's carts for cabinet management.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $search = trim((string) $request->input('search', ''));
        $sortBy = (string) $request->input('sort_by', 'updated_at');
        if (! in_array($sortBy, self::SORT_FIELDS, true)) {
            $sortBy = 'updated_at';
        }
        $sortOrder = strtolower((string) $request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $amountFrom = $request->filled('amount_from') ? (float) $request->input('amount_from') : null;
        $amountTo = $request->filled('amount_to') ? (float) $request->input('amount_to') : null;
        $itemsFrom = $request->filled('items_count_from') ? (int) $request->input('items_count_from') : null;
        $itemsTo = $request->filled('items_count_to') ? (int) $request->input('items_count_to') : null;
        $onlyEmpty = $request->boolean('only_empty');
        $onlyActive = $request->boolean('only_active');

        $cartsCount = $user->carts()->count();

        $query = Cart::query()
            ->where('carts.user_id', $user->id)
            ->withCount('items')
            ->with(['items.product']);

        $this->applyCartSearch($query, $search);

        if ($onlyEmpty) {
            $query->doesntHave('items');
        }
        if ($onlyActive) {
            $query->where('carts.is_active', true);
        }
        if ($amountFrom !== null) {
            $query->whereRaw(self::TOTAL_AMOUNT_SUBQUERY.' >= ?', [$amountFrom]);
        }
        if ($amountTo !== null) {
            $query->whereRaw(self::TOTAL_AMOUNT_SUBQUERY.' <= ?', [$amountTo]);
        }
        if ($itemsFrom !== null) {
            $query->whereRaw(self::ITEMS_COUNT_SUBQUERY.' >= ?', [$itemsFrom]);
        }
        if ($itemsTo !== null) {
            $query->whereRaw(self::ITEMS_COUNT_SUBQUERY.' <= ?', [$itemsTo]);
        }

        $direction = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        match ($sortBy) {
            'name' => $query->orderBy('carts.name', $sortOrder)->orderBy('carts.id'),
            'created_at' => $query->orderBy('carts.created_at', $sortOrder)->orderBy('carts.id'),
            'total_amount' => $query->orderByRaw(self::TOTAL_AMOUNT_SUBQUERY.' '.$direction)->orderBy('carts.id'),
            'items_count' => $query->orderByRaw(self::ITEMS_COUNT_SUBQUERY.' '.$direction)->orderBy('carts.id'),
            default => $query->orderBy('carts.updated_at', $sortOrder)->orderBy('carts.id'),
        };

        $paginated = $query->paginate(20)->withQueryString();

        $cartsData = collect($paginated->items())->map(function (Cart $cart) use ($user, $cartsCount, $search) {
            $summary = $this->cartService->getCartSummary($cart, $user);

            return [
                'id' => $cart->id,
                'name' => $cart->name,
                'is_active' => $cart->is_active,
                'items_count' => (int) $cart->items_count,
                'total_quantity' => $cart->items->sum('quantity'),
                'total_amount' => round($summary['total_price'] ?? 0, 2),
                'created_at' => $cart->created_at?->format('d.m.Y H:i'),
                'updated_at' => $cart->updated_at?->format('d.m.Y H:i'),
                'can_delete' => $cartsCount > 1,
                'match_source' => $this->detectMatchSource($cart, $search),
            ];
        });

        return Inertia::render('User/Cabinet/Carts/Index', [
            'carts' => [
                'data' => $cartsData,
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'cartsCount' => $cartsCount,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'amount_from' => $request->input('amount_from', ''),
                'amount_to' => $request->input('amount_to', ''),
                'items_count_from' => $request->input('items_count_from', ''),
                'items_count_to' => $request->input('items_count_to', ''),
                'only_empty' => $onlyEmpty,
                'only_active' => $onlyActive,
            ],
            'sortFields' => self::SORT_FIELDS,
        ]);
    }

    private function applyCartSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $type = QueryRouter::classify($search);
        $like = '%'.$search.'%';

        $query->where(function (Builder $q) use ($search, $type, $like) {
            $q->where('carts.name', 'like', $like)
                ->orWhereHas('items.product', function (Builder $p) use ($search, $type, $like) {
                    $p->where(function (Builder $pp) use ($search, $type, $like) {
                        $pp->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('code', 'like', $like)
                            ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $like));

                        if ($type === QueryRouter::TYPE_BARCODE) {
                            $pp->orWhereHas('barcodes', fn (Builder $bq) => $bq->where('barcode', $search));
                        } else {
                            $pp->orWhereHas('barcodes', fn (Builder $bq) => $bq->where('barcode', 'like', $like));
                        }
                    });
                });
        });
    }

    private function detectMatchSource(Cart $cart, string $search): ?string
    {
        if ($search === '') {
            return null;
        }
        if (mb_stripos((string) $cart->name, $search) !== false) {
            return 'name';
        }

        return 'composition';
    }

    /**
     * @deprecated Используйте маршрут `cart.show` (`/cart/{cart}`) — единая страница корзины.
     * Оставлен только для редиректа со старого URL `/cabinet/carts/{cart}` на актуальную страницу.
     */
    public function show(Cart $cart): RedirectResponse
    {
        $user = Auth::user();
        abort_if($cart->user_id !== $user->id, 403, 'Доступ запрещён.');

        return redirect()->route('cart.show', $cart);
    }

    /**
     * Rename a cart.
     */
    public function rename(Request $request, Cart $cart): JsonResponse
    {
        $user = Auth::user();
        abort_if($cart->user_id !== $user->id, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ], [
            'name.required' => 'Введите название корзины.',
            'name.max' => 'Название не должно превышать 255 символов.',
        ]);

        $this->cartService->renameCart($user, $cart, $validated['name']);

        return response()->json(['success' => true, 'name' => $validated['name']]);
    }

    /**
     * Delete a cart (returns JSON for cabinet).
     */
    public function destroy(Cart $cart): JsonResponse
    {
        $user = Auth::user();
        abort_if($cart->user_id !== $user->id, 403);

        try {
            $this->cartService->deleteCart($user, $cart);
        } catch (\LogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Switch active cart (returns JSON for cabinet).
     */
    public function switchCart(Cart $cart): JsonResponse
    {
        $user = Auth::user();
        abort_if($cart->user_id !== $user->id, 403);

        $this->cartService->switchActiveCart($user, $cart);

        return response()->json(['success' => true]);
    }

    /**
     * Search products for adding to cart.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $userId = Auth::id();
        $type = QueryRouter::classify($query);
        $limit = 15;

        $purchasedStatuses = [
            OrderStatus::CONFIRMED->value,
            OrderStatus::READY_TO_SHIP->value,
            OrderStatus::CLOSED->value,
        ];

        $purchasedCountQuery = function ($q) use ($userId, $purchasedStatuses) {
            $q->whereHas('order', fn ($o) => $o->where('user_id', $userId)
                ->whereIn('status', $purchasedStatuses));
        };

        $exactBarcodeProduct = null;
        if ($type === QueryRouter::TYPE_BARCODE) {
            $exactBarcodeProduct = Product::query()
                ->whereHas('barcodes', fn ($q) => $q->where('barcode', $query))
                ->with(['brand', 'media'])
                ->withCount(['orderItems as purchased_count' => $purchasedCountQuery])
                ->first();
        }

        $fuzzyIds = FuzzyProductMatcher::isApplicable($query, $type)
            ? FuzzyProductMatcher::findProductIds($query)
            : [];

        $base = Product::query()
            ->where(function ($q) use ($query, $fuzzyIds) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('barcodes', fn ($bq) => $bq->where('barcode', 'like', "%{$query}%"));

                if (! empty($fuzzyIds)) {
                    $q->orWhereIn('id', $fuzzyIds);
                }
            })
            ->with(['brand', 'media'])
            ->withCount(['orderItems as purchased_count' => $purchasedCountQuery]);

        if ($exactBarcodeProduct !== null) {
            $base->where('id', '!=', $exactBarcodeProduct->id);
        }

        $base->orderByRaw('CASE
                WHEN sku = ? THEN 1
                WHEN code = ? THEN 1
                WHEN sku LIKE ? THEN 2
                WHEN code LIKE ? THEN 2
                ELSE 3
            END', [$query, $query, $query.'%', $query.'%'])
            ->orderBy('id');

        $rest = $base->limit($exactBarcodeProduct ? $limit - 1 : $limit)->get();

        $collection = $exactBarcodeProduct
            ? collect([$exactBarcodeProduct])->concat($rest)
            : $rest;

        $favorites = Favorite::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $collection->pluck('id'))
            ->pluck('product_id')
            ->all();

        $stock = $this->resolveStockMap(Auth::user(), $collection->pluck('id')->all());

        $products = $collection->map(function ($product) use ($exactBarcodeProduct, $favorites, $stock) {
            $isExactBarcode = $exactBarcodeProduct && $product->id === $exactBarcodeProduct->id;
            $available = (int) ($stock['available'][$product->id] ?? 0);
            $preorder = (int) ($stock['preorder'][$product->id] ?? 0);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'base_price' => $product->base_price,
                'image_url' => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
                'brand_name' => $product->brand?->name,
                'purchased_count' => (int) ($product->purchased_count ?? 0),
                'in_favorites' => in_array($product->id, $favorites, true),
                'match_source' => $isExactBarcode ? 'barcode_exact' : null,
                'stock_available' => $available,
                'stock_preorder' => $preorder,
            ];
        })->values();

        return response()->json($products);
    }

    /**
     * Считает остатки по складам региона пользователя одним SQL-запросом
     * на каждый тип склада. Возвращает ['available' => [productId => qty], 'preorder' => [...]].
     *
     * @param  array<int, int>  $productIds
     * @return array{available: array<int,int>, preorder: array<int,int>}
     */
    private function resolveStockMap(?\App\Models\User $user, array $productIds): array
    {
        $empty = ['available' => [], 'preorder' => []];

        if (empty($productIds)) {
            return $empty;
        }

        $regionId = $user?->region_id ?? Region::defaultId();
        if (! $regionId) {
            return $empty;
        }

        $warehouseTypes = DB::table('region_warehouse')
            ->where('region_id', $regionId)
            ->whereIn('type', ['primary', 'preorder'])
            ->select('warehouse_id', 'type')
            ->get()
            ->groupBy('type')
            ->map(fn ($rows) => $rows->pluck('warehouse_id')->all());

        $sumStock = function (array $warehouseIds) use ($productIds): array {
            if (empty($warehouseIds)) {
                return [];
            }

            return DB::table('product_warehouse')
                ->whereIn('product_id', $productIds)
                ->whereIn('warehouse_id', $warehouseIds)
                ->selectRaw('product_id, SUM(quantity) as total')
                ->groupBy('product_id')
                ->pluck('total', 'product_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        };

        return [
            'available' => $sumStock($warehouseTypes['primary'] ?? []),
            'preorder' => $sumStock($warehouseTypes['preorder'] ?? []),
        ];
    }
}
