<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Cart\CartServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CartController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService
    ) {}

    /**
     * Display a listing of the user's carts.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Cart::class);

        $query = Cart::with('items.product');

        if (!$request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }

        $carts = $query->get();

        // Add summary to each cart
        $summaries = $this->cartService->getCartsSummary($carts, $request->user());

        $result = $carts->map(function ($cart) use ($summaries) {
            return [
                'id' => $cart->id,
                'name' => $cart->name,
                'created_at' => $cart->created_at,
                'updated_at' => $cart->updated_at,
                'summary' => $summaries[$cart->id] ?? null,
            ];
        });

        return response()->json(['data' => $result]);
    }

    /**
     * Store a newly created cart.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Cart::class);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $cart = Cart::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'] ?? 'Корзина',
        ]);

        return response()->json([
            'message' => 'Cart created',
            'cart' => $cart,
        ], 201);
    }

    /**
     * Display the specified cart.
     */
    public function show(Request $request, Cart $cart): JsonResponse
    {
        Gate::authorize('view', $cart);

        $cart->load('items.product');

        $summary = $this->cartService->getCartSummary($cart, $request->user());

        return response()->json([
            'cart' => [
                'id' => $cart->id,
                'name' => $cart->name,
                'created_at' => $cart->created_at,
                'updated_at' => $cart->updated_at,
                'items' => $cart->items,
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * Update the specified cart.
     */
    public function update(Request $request, Cart $cart): JsonResponse
    {
        Gate::authorize('update', $cart);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $cart->update($validated);

        return response()->json([
            'message' => 'Cart updated',
            'cart' => $cart,
        ]);
    }

    /**
     * Remove the specified cart.
     */
    public function destroy(Cart $cart): JsonResponse
    {
        Gate::authorize('delete', $cart);

        $cart->delete();

        return response()->json([
            'message' => 'Cart deleted',
        ]);
    }

    /**
     * Add an item to the cart.
     */
    public function addItem(Request $request, Cart $cart): JsonResponse
    {
        Gate::authorize('addItem', $cart);

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $quantity = $validated['quantity'] ?? 1;

        // Check if item already exists
        $existingItem = $cart->items()
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($existingItem) {
            // Update quantity
            $existingItem->update([
                'quantity' => $existingItem->quantity + $quantity,
            ]);

            return response()->json([
                'message' => 'Cart item quantity updated',
                'item' => $existingItem->load('product'),
            ]);
        }

        $item = $cart->items()->create([
            'product_id' => $validated['product_id'],
            'quantity' => $quantity,
        ]);

        return response()->json([
            'message' => 'Item added to cart',
            'item' => $item->load('product'),
        ], 201);
    }

    /**
     * Update an item in the cart.
     */
    public function updateItem(Request $request, Cart $cart, CartItem $item): JsonResponse
    {
        Gate::authorize('updateItem', $cart);

        // Ensure the item belongs to this cart
        if ($item->cart_id !== $cart->id) {
            abort(404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item->update($validated);

        return response()->json([
            'message' => 'Cart item updated',
            'item' => $item->load('product'),
        ]);
    }

    /**
     * Remove an item from the cart.
     */
    public function removeItem(Cart $cart, CartItem $item): JsonResponse
    {
        Gate::authorize('removeItem', $cart);

        // Ensure the item belongs to this cart
        if ($item->cart_id !== $cart->id) {
            abort(404);
        }

        $item->delete();

        return response()->json([
            'message' => 'Item removed from cart',
        ]);
    }
}
