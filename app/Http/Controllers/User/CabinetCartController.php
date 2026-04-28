<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Favorite;
use App\Models\Product;
use App\Support\Search\QueryRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CabinetCartController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService,
    ) {}

    /**
     * List all user's carts for cabinet management.
     */
    public function index()
    {
        $user = Auth::user();

        $carts = $user->carts()
            ->withCount('items')
            ->with(['items.product'])
            ->orderBy('id')
            ->get();

        $cartsCount = $carts->count();

        $cartsData = $carts->map(function ($cart) use ($user, $cartsCount) {
            $summary = $this->cartService->getCartSummary($cart, $user);

            return [
                'id' => $cart->id,
                'name' => $cart->name,
                'is_active' => $cart->is_active,
                'items_count' => $cart->items_count,
                'total_quantity' => $cart->items->sum('quantity'),
                'total_amount' => round($summary['total_price'] ?? 0, 2),
                'created_at' => $cart->created_at?->format('d.m.Y H:i'),
                'updated_at' => $cart->updated_at?->format('d.m.Y H:i'),
                'can_delete' => $cartsCount > 1,
            ];
        });

        return Inertia::render('User/Cabinet/Carts/Index', [
            'carts' => $cartsData,
            'cartsCount' => $cartsCount,
        ]);
    }

    /**
     * Show a single cart with items for editing in cabinet.
     */
    public function show(Cart $cart)
    {
        $user = Auth::user();
        abort_if($cart->user_id !== $user->id, 403, 'Доступ запрещён.');

        $details = $this->cartService->getCartDetails($cart, $user);

        $cartsCount = $user->carts()->count();

        return Inertia::render('User/Cabinet/Carts/Show', [
            'cart' => [
                'id' => $cart->id,
                'name' => $cart->name,
                'is_active' => $cart->is_active,
                'created_at' => $cart->created_at?->format('d.m.Y H:i'),
                'updated_at' => $cart->updated_at?->format('d.m.Y H:i'),
                'can_delete' => $cartsCount > 1,
            ],
            'cartDetails' => $details,
        ]);
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

        $base = Product::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('barcodes', fn ($bq) => $bq->where('barcode', 'like', "%{$query}%"));
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

        $products = $collection->map(function ($product) use ($exactBarcodeProduct, $favorites) {
            $isExactBarcode = $exactBarcodeProduct && $product->id === $exactBarcodeProduct->id;

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
            ];
        })->values();

        return response()->json($products);
    }
}
