<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CartController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService
    ) {}

    // ────────────────────────────────────────────
    // Web Routes (Inertia)
    // ────────────────────────────────────────────

    /**
     * Redirect to active cart page.
     * GET /cart
     */
    public function index(Request $request): RedirectResponse
    {
        $cart = $this->cartService->getOrCreateActiveCart($request->user());

        return redirect()->route('cart.show', $cart);
    }

    /**
     * Show cart page.
     * GET /cart/{cart}
     */
    public function show(Request $request, Cart $cart): InertiaResponse
    {
        Gate::authorize('view', $cart);

        $user = $request->user();
        $cartDetails = $this->cartService->getCartDetails($cart, $user);

        // Get all user's carts for the cart manager
        $userCarts = $user->carts()
            ->withSum('items', 'quantity')
            ->orderBy('id')
            ->get()
            ->map(fn (Cart $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_active' => $c->is_active,
                'items_count' => (int) ($c->items_sum_quantity ?? 0),
            ]);

        return Inertia::render('User/Cart/Index', [
            'cart' => [
                'id' => $cart->id,
                'name' => $cart->name,
                'is_active' => $cart->is_active,
                'description' => $cart->description,
            ],
            'cartDetails' => $cartDetails,
            'userCarts' => $userCarts,
        ]);
    }

    /**
     * Create a new cart.
     * POST /cart
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $cart = $this->cartService->createCart($request->user(), $validated['name'] ?? null);

        return redirect()->route('cart.show', $cart)
            ->with('success', 'Корзина создана.');
    }

    /**
     * Switch active cart.
     * POST /cart/{cart}/switch
     */
    public function switch(Request $request, Cart $cart): RedirectResponse
    {
        Gate::authorize('update', $cart);

        $this->cartService->switchActiveCart($request->user(), $cart);

        return redirect()->route('cart.show', $cart)
            ->with('success', 'Активная корзина переключена.');
    }

    /**
     * Rename cart.
     * PATCH /cart/{cart}
     */
    public function update(Request $request, Cart $cart): RedirectResponse
    {
        Gate::authorize('update', $cart);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $this->cartService->renameCart($request->user(), $cart, $validated['name']);

        return redirect()->route('cart.show', $cart)
            ->with('success', 'Корзина переименована.');
    }

    /**
     * Delete cart.
     * DELETE /cart/{cart}
     */
    public function destroy(Request $request, Cart $cart): RedirectResponse
    {
        Gate::authorize('delete', $cart);

        try {
            $this->cartService->deleteCart($request->user(), $cart);
        } catch (\LogicException $e) {
            return redirect()->route('cart.show', $cart)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('cart.index')
            ->with('success', 'Корзина удалена.');
    }

    // ────────────────────────────────────────────
    // API Routes
    // ────────────────────────────────────────────

    /**
     * Get cart summary.
     * GET /api/cart/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        return response()->json($this->cartService->getCartItemsSummary($cart));
    }

    /**
     * Get active cart quantities map.
     * GET /api/cart/active-quantities
     */
    public function activeQuantities(Request $request): JsonResponse
    {
        $quantities = $this->cartService->getActiveQuantities($request->user());

        return response()->json($quantities);
    }

    /**
     * Set product quantity in active cart (main spillover API).
     * POST /api/cart/set-product-quantity
     */
    public function setProductQuantity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:0',
        ], [
            'product_id.required' => 'Укажите товар.',
            'product_id.exists' => 'Товар не найден.',
            'quantity.required' => 'Укажите количество.',
            'quantity.integer' => 'Количество должно быть целым числом.',
            'quantity.min' => 'Количество не может быть отрицательным.',
        ]);

        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);
        $product = Product::findOrFail($validated['product_id']);

        $result = $this->cartService->setProductQuantity($user, $cart, $product, $validated['quantity']);

        return response()->json([
            'status' => 'success',
            'message' => 'Количество обновлено.',
            ...$result,
        ]);
    }

    /**
     * Add product to cart.
     * POST /api/cart/add-product
     */
    public function addProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'integer|min:1',
        ], [
            'product_id.required' => 'Укажите товар.',
            'product_id.exists' => 'Товар не найден.',
            'quantity.integer' => 'Количество должно быть целым числом.',
            'quantity.min' => 'Количество должно быть не менее 1.',
        ]);

        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);
        $product = Product::findOrFail($validated['product_id']);
        $qty = $validated['quantity'] ?? 1;

        $result = $this->cartService->addProduct($user, $cart, $product, $qty);

        return response()->json([
            'status' => 'success',
            'message' => 'Товар добавлен в корзину.',
            ...$result,
        ], 201);
    }

    /**
     * Add product by barcode.
     * POST /api/cart/add-by-barcode
     */
    public function addByBarcode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => 'required|string',
            'quantity' => 'integer|min:1',
        ], [
            'barcode.required' => 'Укажите штрихкод.',
            'quantity.integer' => 'Количество должно быть целым числом.',
            'quantity.min' => 'Количество должно быть не менее 1.',
        ]);

        $productBarcode = ProductBarcode::where('barcode', $validated['barcode'])->first();

        if (! $productBarcode) {
            return response()->json([
                'status' => 'error',
                'message' => 'Товар с таким штрихкодом не найден.',
            ], 404);
        }

        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);
        $product = $productBarcode->product;
        $qty = $validated['quantity'] ?? 1;

        // Remember previous quantity to detect if anything was actually added
        $previousQty = $cart->items()
            ->where('product_id', $product->id)
            ->sum('quantity');

        $result = $this->cartService->addProduct($user, $cart, $product, $qty);

        $actualTotal = $result['instock'] + $result['preorder'];

        // Nothing was added — max stock already reached
        if ($actualTotal <= $previousQty) {
            return response()->json([
                'status' => 'warning',
                'message' => "Достигнут максимум для «{$product->name}» ({$result['max_total']} шт.)",
                'product_id' => $product->id,
                'product_name' => $product->name,
                ...$result,
            ]);
        }

        $addedQty = $actualTotal - $previousQty;

        // Partially added (was clamped)
        if ($addedQty < $qty) {
            return response()->json([
                'status' => 'partial',
                'message' => "Добавлено {$addedQty} из {$qty} шт. «{$product->name}» (макс. {$result['max_total']} шт.)",
                'product_id' => $product->id,
                'product_name' => $product->name,
                ...$result,
            ], 201);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Товар добавлен в корзину.',
            'product_id' => $product->id,
            'product_name' => $product->name,
            ...$result,
        ], 201);
    }

    /**
     * Update item quantity.
     * PATCH /api/cart/items/{item}
     */
    public function updateItem(Request $request, CartItem $item): JsonResponse
    {
        // Check ownership
        $cart = $item->cart;
        if ($cart->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещён.');
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ], [
            'quantity.required' => 'Укажите количество.',
            'quantity.integer' => 'Количество должно быть целым числом.',
            'quantity.min' => 'Количество должно быть не менее 1.',
        ]);

        $result = $this->cartService->updateItemQuantity($request->user(), $item, $validated['quantity']);

        return response()->json([
            'status' => 'success',
            'message' => 'Количество обновлено.',
            ...$result,
        ]);
    }

    /**
     * Remove item from cart.
     * DELETE /api/cart/items/{item}
     */
    public function removeItem(Request $request, CartItem $item): JsonResponse
    {
        // Check ownership
        $cart = $item->cart;
        if ($cart->user_id !== $request->user()->id) {
            abort(403, 'Доступ запрещён.');
        }

        $this->cartService->removeItem($request->user(), $item);

        return response()->json([
            'status' => 'success',
            'message' => 'Товар удалён из корзины.',
        ]);
    }

    /**
     * Move selected products to another cart.
     * POST /api/cart/move-items
     */
    public function moveItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_cart_id' => 'required|integer|exists:carts,id',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ], [
            'target_cart_id.required' => 'Укажите целевую корзину.',
            'target_cart_id.exists' => 'Целевая корзина не найдена.',
            'product_ids.required' => 'Выберите товары для переноса.',
            'product_ids.min' => 'Выберите хотя бы один товар.',
        ]);

        $user = $request->user();
        $sourceCart = $this->cartService->getOrCreateActiveCart($user);
        $targetCart = Cart::findOrFail($validated['target_cart_id']);

        // Both carts must belong to the user
        if ($targetCart->user_id !== $user->id) {
            abort(403, 'Доступ запрещён.');
        }

        // Cannot move to the same cart
        if ($sourceCart->id === $targetCart->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Нельзя перенести товары в ту же корзину.',
            ], 422);
        }

        $this->cartService->moveItems($sourceCart, $targetCart, $validated['product_ids']);

        $movedCount = count($validated['product_ids']);

        return response()->json([
            'status' => 'success',
            'message' => "Перенесено {$movedCount} товаров в корзину «{$targetCart->name}».",
            'moved_count' => $movedCount,
            'target_cart_id' => $targetCart->id,
            'target_cart_name' => $targetCart->name,
        ]);
    }

    /**
     * Clear active cart.
     * DELETE /api/cart/clear
     */
    public function clear(Request $request): JsonResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);
        $cart->clear();

        return response()->json([
            'status' => 'success',
            'message' => 'Корзина очищена.',
        ]);
    }

    // ────────────────────────────────────────────
    // API — Cart Management (JSON, for header dropdown)
    // ────────────────────────────────────────────

    /**
     * Get list of user's carts.
     * GET /api/cart/user-carts
     */
    public function userCarts(Request $request): JsonResponse
    {
        $carts = $request->user()
            ->carts()
            ->withSum('items', 'quantity')
            ->orderBy('id')
            ->get()
            ->map(fn (Cart $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_active' => $c->is_active,
                'items_count' => (int) ($c->items_sum_quantity ?? 0),
            ]);

        return response()->json($carts);
    }

    /**
     * Create a new cart (API, JSON response).
     * POST /api/cart/carts
     */
    public function apiStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $cart = $this->cartService->createCart($request->user(), $validated['name'] ?? null);

        return response()->json([
            'status' => 'success',
            'message' => 'Корзина создана.',
            'cart' => [
                'id' => $cart->id,
                'name' => $cart->name,
                'is_active' => $cart->is_active,
                'items_count' => 0,
            ],
        ], 201);
    }

    /**
     * Switch active cart (API, JSON response).
     * POST /api/cart/carts/{cart}/switch
     */
    public function apiSwitch(Request $request, Cart $cart): JsonResponse
    {
        Gate::authorize('update', $cart);

        $this->cartService->switchActiveCart($request->user(), $cart);

        return response()->json([
            'status' => 'success',
            'message' => 'Активная корзина переключена.',
        ]);
    }

    /**
     * Rename cart (API, JSON response).
     * PATCH /api/cart/carts/{cart}
     */
    public function apiUpdate(Request $request, Cart $cart): JsonResponse
    {
        Gate::authorize('update', $cart);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $this->cartService->renameCart($request->user(), $cart, $validated['name']);

        return response()->json([
            'status' => 'success',
            'message' => 'Корзина переименована.',
        ]);
    }

    /**
     * Delete cart (API, JSON response).
     * DELETE /api/cart/carts/{cart}
     */
    public function apiDestroy(Request $request, Cart $cart): JsonResponse
    {
        Gate::authorize('delete', $cart);

        try {
            $this->cartService->deleteCart($request->user(), $cart);
        } catch (\LogicException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Корзина удалена.',
        ]);
    }
}
