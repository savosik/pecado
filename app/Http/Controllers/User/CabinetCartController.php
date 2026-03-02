<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
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
                'id'             => $cart->id,
                'name'           => $cart->name,
                'is_active'      => $cart->is_active,
                'items_count'    => $cart->items_count,
                'total_quantity' => $cart->items->sum('quantity'),
                'total_amount'   => round($summary['total_price'] ?? 0, 2),
                'created_at'     => $cart->created_at?->format('d.m.Y H:i'),
                'updated_at'     => $cart->updated_at?->format('d.m.Y H:i'),
                'can_delete'     => $cartsCount > 1,
            ];
        });

        return Inertia::render('User/Cabinet/Carts/Index', [
            'carts'      => $cartsData,
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
                'id'        => $cart->id,
                'name'      => $cart->name,
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
        $query = $request->input('query', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $products = Product::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('sku', 'like', "%{$query}%")
                  ->orWhere('code', 'like', "%{$query}%")
                  ->orWhereHas('barcodes', function ($bq) use ($query) {
                      $bq->where('barcode', 'like', "%{$query}%");
                  });
            })
            ->with(['brand', 'media'])
            ->limit(15)
            ->get()
            ->map(function ($product) {
                return [
                    'id'         => $product->id,
                    'name'       => $product->name,
                    'sku'        => $product->sku,
                    'base_price' => $product->base_price,
                    'image_url'  => $product->getFirstMediaUrl('main', 'thumb') ?: $product->getFirstMediaUrl('main'),
                    'brand_name' => $product->brand?->name,
                ];
            });

        return response()->json($products);
    }
}
