<?php

namespace App\Http\Controllers\User;

use App\Contracts\Cart\CartServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartPromotionSelection;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductDefect;
use App\Models\PromotionRule;
use App\Services\Cart\OrderImportService;
use App\Services\Promotion\CartPromotionProgress;
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
     * Прогресс акций по корзине: «доберите на X» и «условия выполнены».
     * GET /api/cart/promotions
     *
     * Причины несрабатывания правил (нет остатка, исчерпан лимит) в ответ
     * не попадают — см. CartPromotionProgress.
     */
    public function promotions(Request $request, CartPromotionProgress $progress): JsonResponse
    {
        $user = $request->user();

        if ($cartId = $request->integer('cart_id')) {
            $cart = Cart::query()->findOrFail($cartId);
            Gate::authorize('view', $cart);
        } else {
            $cart = $this->cartService->getOrCreateActiveCart($user);
        }

        return response()->json($progress->forCart($cart, $user));
    }

    /**
     * Промо-строки корзины.
     * GET /api/cart/promo-items
     *
     * Строки виртуальные и пересчитываются движком на каждый вызов, поэтому
     * после изменения количеств их нужно перезапрашивать: Inertia-пропсы
     * страницы при работе через store не обновляются.
     */
    public function promoItems(Request $request): JsonResponse
    {
        return $this->promoResponse($this->promoCart($request));
    }

    /**
     * Выбрать товар из вариантов награды.
     * POST /api/cart/promo/select
     */
    public function selectPromo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rule_id' => 'required|integer|exists:promotion_rules,id',
            'reward_index' => 'required|integer|min:0',
            'product_id' => 'required|integer|exists:products,id',
        ], [
            'rule_id.required' => 'Не указана акция',
            'rule_id.exists' => 'Акция не найдена',
            'reward_index.required' => 'Не указана награда',
            'product_id.required' => 'Не выбран товар',
            'product_id.exists' => 'Товар не найден',
        ]);

        $cart = $this->promoCart($request);

        CartPromotionSelection::updateOrCreate(
            [
                'cart_id' => $cart->id,
                'promotion_rule_id' => $validated['rule_id'],
                'reward_index' => $validated['reward_index'],
            ],
            ['product_id' => $validated['product_id']],
        );

        return $this->promoResponse($cart);
    }

    /**
     * Отказаться от платной промо-позиции или вернуть её.
     * POST /api/cart/promo/decline
     */
    public function declinePromo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rule_id' => 'required|integer|exists:promotion_rules,id',
            'reward_index' => 'required|integer|min:0',
            'declined' => 'required|boolean',
        ], [
            'rule_id.required' => 'Не указана акция',
            'rule_id.exists' => 'Акция не найдена',
            'reward_index.required' => 'Не указана награда',
            'declined.required' => 'Не указано действие',
        ]);

        $cart = $this->promoCart($request);

        // От бесплатного не отказываются: подарок ничего не стоит, а кнопка
        // «отказаться» рядом с ним выглядит как ошибка интерфейса
        if ($validated['declined'] && ! $this->promoRewardIsOptional($cart, $validated['rule_id'], $validated['reward_index'])) {
            return response()->json([
                'message' => 'От этой промо-позиции нельзя отказаться',
            ], 422);
        }

        CartPromotionSelection::updateOrCreate(
            [
                'cart_id' => $cart->id,
                'promotion_rule_id' => $validated['rule_id'],
                'reward_index' => $validated['reward_index'],
            ],
            ['is_declined' => $validated['declined']],
        );

        return $this->promoResponse($cart);
    }

    /**
     * Корзина, к которой относится выбор по акции.
     */
    private function promoCart(Request $request): Cart
    {
        if ($cartId = $request->integer('cart_id')) {
            $cart = Cart::query()->findOrFail($cartId);
            Gate::authorize('view', $cart);

            return $cart;
        }

        return $this->cartService->getOrCreateActiveCart($request->user());
    }

    /**
     * Отклоняемая ли награда: платная и помеченная `optional` в правиле.
     */
    private function promoRewardIsOptional(Cart $cart, int $ruleId, int $rewardIndex): bool
    {
        $rule = PromotionRule::find($ruleId);
        $reward = (array) (array_values((array) ($rule?->rewards ?? []))[$rewardIndex] ?? []);

        return (float) ($reward['price'] ?? 0) > 0 && (bool) ($reward['optional'] ?? true);
    }

    /**
     * Пересчитанный блок промо целиком — фронт не склеивает состояние сам.
     */
    private function promoResponse(Cart $cart): JsonResponse
    {
        $details = $this->cartService->getCartDetails($cart->fresh(), $cart->user);

        return response()->json([
            'promo_items' => $details['promo_items'],
            'promo_quantity' => $details['promo_quantity'],
            'promo_amount' => $details['promo_amount'],
        ]);
    }

    /**
     * Get active cart snapshot: quantities map + totals.
     * GET /api/cart/active-quantities
     */
    public function activeQuantities(Request $request): JsonResponse
    {
        return response()->json(
            $this->cartService->getActiveCartSnapshot($request->user())
        );
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
     * Bulk set product quantity in active cart.
     * POST /api/cart/set-products-quantity
     *
     * Принимает массив items: [{product_id, quantity}, ...] и обрабатывает
     * всё в одной транзакции — в отличие от N параллельных вызовов
     * /api/cart/set-product-quantity, которые приводят к дедлокам InnoDB
     * на cart_items одной корзины.
     */
    public function setProductsQuantity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:0',
        ], [
            'items.required' => 'Укажите список товаров.',
            'items.array' => 'Список товаров должен быть массивом.',
            'items.min' => 'Список товаров не может быть пустым.',
            'items.max' => 'Слишком много товаров в одном запросе.',
            'items.*.product_id.required' => 'Укажите товар.',
            'items.*.product_id.exists' => 'Товар не найден.',
            'items.*.quantity.required' => 'Укажите количество.',
            'items.*.quantity.integer' => 'Количество должно быть целым числом.',
            'items.*.quantity.min' => 'Количество не может быть отрицательным.',
        ]);

        // Сворачиваем дубли по product_id (последнее значение побеждает).
        $quantities = [];
        foreach ($validated['items'] as $row) {
            $quantities[(int) $row['product_id']] = (int) $row['quantity'];
        }

        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        $result = $this->cartService->setProductsQuantity($user, $cart, $quantities);

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
     * Добавить уценённую партию в корзину.
     * POST /api/cart/add-defect
     */
    public function addDefect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'defect_id' => 'required|exists:product_defects,id',
            'quantity' => 'integer|min:1',
        ], [
            'defect_id.required' => 'Укажите позицию уценки.',
            'defect_id.exists' => 'Позиция уценки не найдена.',
            'quantity.integer' => 'Количество должно быть целым числом.',
            'quantity.min' => 'Количество должно быть не менее 1.',
        ]);

        $defect = ProductDefect::findOrFail($validated['defect_id']);

        // Партия должна быть допущена в продажу — иначе устаревшая вкладка
        // не должна протащить в корзину снятую с продажи уценку.
        if (! $defect->is_published || $defect->price === null || $defect->isClosed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Эта позиция уценки больше не доступна.',
            ], 422);
        }

        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);
        $qty = $validated['quantity'] ?? 1;

        $result = $this->cartService->addDefect($user, $cart, $defect, $qty);

        if ($result['quantity'] <= 0) {
            return response()->json([
                'status' => 'warning',
                'message' => 'Эта позиция уценки уже разобрана.',
                ...$result,
            ], 200);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Уценённый товар добавлен в корзину.',
            ...$result,
        ], 201);
    }

    /**
     * Задать точное количество уценённой партии в корзине (0 = удалить).
     * Аналог set-product-quantity, но для партии некондиции.
     * POST /api/cart/set-defect-quantity
     */
    public function setDefectQuantity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'defect_id' => 'required|exists:product_defects,id',
            'quantity' => 'required|integer|min:0',
        ], [
            'defect_id.required' => 'Укажите позицию уценки.',
            'defect_id.exists' => 'Позиция уценки не найдена.',
        ]);

        $defect = ProductDefect::findOrFail($validated['defect_id']);
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        $result = $this->cartService->setDefectQuantity($user, $cart, $defect, (int) $validated['quantity']);

        return response()->json([
            'status' => 'success',
            'defect_id' => $defect->id,
            ...$result,
        ]);
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

        // Уценка меняется по своим правилам (лимит и цена — от партии), не через spillover.
        if ($item->isDefect()) {
            $defect = $item->productDefect;
            if (! $defect) {
                abort(422, 'Позиция уценки не найдена.');
            }

            $result = $this->cartService->setDefectQuantity($request->user(), $cart, $defect, $validated['quantity']);

            return response()->json([
                'status' => 'success',
                'message' => 'Количество обновлено.',
                ...$result,
            ]);
        }

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

    // ────────────────────────────────────────────
    // API — Мульти-корзинный контрол (страница товара / quick view)
    // ────────────────────────────────────────────

    /**
     * Quantities of a product across all of the user's carts.
     * GET /api/cart/product-quantities/{product}
     */
    public function productQuantities(Request $request, Product $product): JsonResponse
    {
        return response()->json(
            $this->cartService->getProductQuantitiesAcrossCarts($request->user(), $product)
        );
    }

    /**
     * Set product quantity in a specific (possibly non-active) cart.
     * POST /api/cart/carts/{cart}/set-product-quantity
     */
    public function setProductQuantityInCart(Request $request, Cart $cart): JsonResponse
    {
        Gate::authorize('update', $cart);

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
        $product = Product::findOrFail($validated['product_id']);

        $result = $this->cartService->setProductQuantity($user, $cart, $product, $validated['quantity']);

        return response()->json([
            'status' => 'success',
            'message' => 'Количество обновлено.',
            'cart_id' => $cart->id,
            ...$result,
        ]);
    }

    // ────────────────────────────────────────────
    // API — Импорт заказа (список / файл)
    // ────────────────────────────────────────────

    /**
     * Импорт позиций в корзину из списка (два столбца: идентификаторы + количества).
     * POST /api/cart/import-order
     */
    public function importOrder(Request $request, OrderImportService $importer): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:1000',
            'items.*.identifier' => 'required|string',
            'items.*.quantity' => 'required',
        ], [
            'items.required' => 'Укажите список позиций.',
            'items.array' => 'Список позиций должен быть массивом.',
            'items.min' => 'Список позиций не может быть пустым.',
            'items.max' => 'Слишком много позиций в одном импорте.',
            'items.*.identifier.required' => 'Укажите идентификатор товара.',
            'items.*.quantity.required' => 'Укажите количество.',
        ]);

        $resolution = $importer->resolve($validated['items']);

        return $this->respondWithImport($request, $resolution);
    }

    /**
     * Импорт позиций в корзину из загруженного файла (XLSX/CSV).
     * POST /api/cart/import-order-file
     */
    public function importOrderFile(Request $request, OrderImportService $importer): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,txt|max:5120',
        ], [
            'file.required' => 'Прикрепите файл.',
            'file.file' => 'Некорректный файл.',
            'file.mimes' => 'Поддерживаются форматы XLSX и CSV.',
            'file.max' => 'Файл слишком большой (максимум 5 МБ).',
        ]);

        $rows = $importer->parseFile($request->file('file'));

        if (empty($rows)) {
            return response()->json([
                'status' => 'error',
                'message' => 'В файле не найдено ни одной позиции.',
            ], 422);
        }

        $resolution = $importer->resolve($rows);

        return $this->respondWithImport($request, $resolution);
    }

    /**
     * Скачать XLSX-шаблон для импорта заказа.
     * GET /api/cart/import-order/template
     */
    public function importOrderTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Импорт заказа');
        $sheet->fromArray([
            ['Идентификатор', 'Количество'],
            ['ART-000123', 2],
            ['4600000000000', 1],
        ], null, 'A1');
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(16);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'shablon-importa-zakaza.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Применяет разрешённые позиции к активной корзине (аддитивно) и формирует ответ.
     *
     * @param  array{resolved: array<int, array{product_id: int, identifier: string, name: string, quantity: int}>, unresolved: array<int, array{identifier: string, quantity: string, reason: string}>}  $resolution
     */
    private function respondWithImport(Request $request, array $resolution): JsonResponse
    {
        $user = $request->user();
        $cart = $this->cartService->getOrCreateActiveCart($user);

        $resolved = $resolution['resolved'];
        $unresolved = $resolution['unresolved'];

        $cartTotals = null;
        if (! empty($resolved)) {
            // Аддитивно: к текущему количеству каждого товара прибавляем импортируемое.
            $targets = [];
            foreach ($resolved as $row) {
                $pid = (int) $row['product_id'];
                $current = (int) $cart->items()->where('product_id', $pid)->sum('quantity');
                $targets[$pid] = $current + (int) $row['quantity'];
            }

            $result = $this->cartService->setProductsQuantity($user, $cart, $targets);
            $cartTotals = $result['cart_totals'] ?? null;
        }

        $addedCount = count($resolved);
        $message = $addedCount > 0
            ? "Импортировано позиций: {$addedCount}."
            : 'Не удалось импортировать ни одной позиции.';

        return response()->json([
            'status' => $addedCount > 0 ? 'success' : 'warning',
            'message' => $message,
            'added_count' => $addedCount,
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'cart_totals' => $cartTotals,
        ], $addedCount > 0 ? 200 : 422);
    }
}
