<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class CartController extends AdminController
{
    public function __construct(
        protected \App\Services\Pricing\PriceService $priceService
    ) {
        parent::__construct();
    }

    /**
     * Show the form for creating a new cart.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Carts/Create', [
            'currencies' => \App\Models\Currency::select('id', 'name', 'code', 'symbol')->orderBy('id')->get(),
        ]);
    }

    /**
     * Store a newly created cart in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'user_id' => 'required|exists:users,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'user_id.required' => 'Выберите пользователя',
            'user_id.exists' => 'Пользователь не найден',
            'items.required' => 'Добавьте хотя бы один товар',
            'items.min' => 'Добавьте хотя бы один товар',
            'items.*.product_id.required' => 'Выберите товар',
            'items.*.product_id.exists' => 'Товар не найден',
            'items.*.quantity.required' => 'Укажите количество',
            'items.*.quantity.min' => 'Минимальное количество: 1',
        ]);

        DB::beginTransaction();
        try {
            $cart = Cart::create([
                'name' => $validated['name'] ?? null,
                'user_id' => $validated['user_id'],
            ]);

            foreach ($validated['items'] as $item) {
                $cart->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return redirect()
                ->route('admin.carts.edit', $cart)
                ->with('success', 'Корзина успешно создана');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при создании корзины: ' . $e->getMessage()]);
        }
    }

    /**
     * Display a listing of carts.
     */
    public function index(Request $request): Response
    {
        $query = Cart::query()
            ->with(['user', 'items.product']);

        // Поиск по имени пользователя, email или названию корзины
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('id', $search)
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Фильтрация по пользователю
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        // Фильтрация по дате создания
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Фильтрация по наличию товара
        if ($productId = $request->input('product_id')) {
            $query->whereHas('items', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            });
        }

        // Фильтрация по сумме корзины (приблизительно, через подзапрос или join)
        // Так как цена может зависеть от пользователя и валюты, считаем базовую стоимость
        if ($amountMin = $request->input('amount_min')) {
             $query->whereRaw('(SELECT SUM(cart_items.quantity * products.base_price) 
                               FROM cart_items 
                               JOIN products ON products.id = cart_items.product_id 
                               WHERE cart_items.cart_id = carts.id) >= ?', [$amountMin]);
        }
        if ($amountMax = $request->input('amount_max')) {
             $query->whereRaw('(SELECT SUM(cart_items.quantity * products.base_price) 
                               FROM cart_items 
                               JOIN products ON products.id = cart_items.product_id 
                               WHERE cart_items.cart_id = carts.id) <= ?', [$amountMax]);
        }

        // Фильтрация по минимальному количеству товаров
        if ($itemsMin = $request->input('items_min')) {
            $query->has('items', '>=', (int) $itemsMin);
        }
        if ($itemsMax = $request->input('items_max')) {
            $query->has('items', '<=', (int) $itemsMax);
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $carts = $query->paginate($perPage)->withQueryString();

        // Трансформация данных для фронтенда
        $carts->getCollection()->transform(function ($cart) {
            $totalAmount = $cart->items->sum(function ($item) {
                return ($item->product?->base_price ?? 0) * $item->quantity;
            });

            return [
                'id' => $cart->id,
                'name' => $cart->name,
                'user_id' => $cart->user_id,
                'created_at' => $cart->created_at?->format('d.m.Y H:i'),
                'updated_at' => $cart->updated_at?->format('d.m.Y H:i'),
                'user' => $cart->user ? [
                    'id' => $cart->user->id,
                    'name' => $cart->user->name,
                    'email' => $cart->user->email,
                ] : null,
                'items_count' => $cart->items->count(),
                'total_quantity' => $cart->items->sum('quantity'),
                'total_amount' => round($totalAmount, 2),
            ];
        });

        return Inertia::render('Admin/Pages/Carts/Index', [
            'carts' => $carts,
            'filters' => [
                'search' => $search,
                'user_id' => $userId,
                'user' => $userId ? User::select('id', 'name', 'email')->find($userId) : null,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'items_min' => $itemsMin,
                'items_max' => $itemsMax,
                'product_id' => $productId,
                'product' => $productId ? Product::select('id', 'name')->find($productId) : null,
                'amount_min' => $amountMin,
                'amount_max' => $amountMax,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }


    /**
     * Show the form for editing the specified cart.
     */
    public function edit(Cart $cart): Response
    {
        $cart->load(['user', 'items.product.media', 'items.product.brand']);

        $totalAmount = $cart->items->sum(function ($item) {
            return ($item->product?->base_price ?? 0) * $item->quantity;
        });

        return Inertia::render('Admin/Pages/Carts/Edit', [
            'cart' => [
                'id' => $cart->id,
                'name' => $cart->name,
                'user_id' => $cart->user_id,
                'created_at' => $cart->created_at?->format('d.m.Y H:i'),
                'updated_at' => $cart->updated_at?->format('d.m.Y H:i'),
                'user' => $cart->user ? [
                    'id' => $cart->user->id,
                    'name' => $cart->user->name,
                    'email' => $cart->user->email,
                ] : null,
                'items' => $cart->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'product' => $item->product ? [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'base_price' => $item->product->base_price,
                            'sku' => $item->product->sku,
                            'image_url' => $item->product->getFirstMediaUrl('main'),
                            'brand_name' => $item->product->brand?->name,
                        ] : null,
                    ];
                }),
                'total_amount' => round($totalAmount, 2),
            ],
            'currencies' => \App\Models\Currency::select('id', 'name', 'code', 'symbol')->orderBy('id')->get(),
        ]);
    }

    /**
     * Update the specified cart in storage.
     */
    public function update(Request $request, Cart $cart): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'items' => 'required|array',
            'items.*.id' => 'nullable|exists:cart_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            // Обновление названия корзины
            $cart->update([
                'name' => $validated['name'] ?? $cart->name,
            ]);

            // Синхронизация элементов корзины
            $existingItemIds = [];
            foreach ($validated['items'] as $item) {
                if (!empty($item['id'])) {
                    // Обновление существующего элемента
                    $cartItem = $cart->items()->find($item['id']);
                    if ($cartItem) {
                        $cartItem->update([
                            'product_id' => $item['product_id'],
                            'quantity' => $item['quantity'],
                        ]);
                        $existingItemIds[] = $item['id'];
                    }
                } else {
                    // Создание нового элемента
                    $newItem = $cart->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                    ]);
                    $existingItemIds[] = $newItem->id;
                }
            }

            // Удаление элементов, которых больше нет
            $cart->items()->whereNotIn('id', $existingItemIds)->delete();

            DB::commit();

            return redirect()
                ->route('admin.carts.edit', $cart)
                ->with('success', 'Корзина успешно обновлена');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при обновлении корзины: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified cart from storage.
     */
    public function destroy(Cart $cart): RedirectResponse
    {
        $cart->items()->delete();
        $cart->delete();

        return redirect()
            ->route('admin.carts.index')
            ->with('success', 'Корзина успешно удалена');
    }

    /**
     * Bulk delete multiple carts.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cart_ids' => 'required|array|min:1',
            'cart_ids.*' => 'exists:carts,id',
        ]);

        DB::beginTransaction();
        try {
            // Удаление элементов корзин
            CartItem::whereIn('cart_id', $validated['cart_ids'])->delete();
            
            // Удаление корзин
            $count = Cart::whereIn('id', $validated['cart_ids'])->delete();

            DB::commit();

            return redirect()
                ->back()
                ->with('success', "Удалено корзин: {$count}");
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withErrors(['error' => 'Ошибка при удалении корзин: ' . $e->getMessage()]);
        }
    }

    /**
     * Clear all items from a cart.
     */
    public function clearItems(Cart $cart): RedirectResponse
    {
        $cart->items()->delete();

        return redirect()
            ->back()
            ->with('success', 'Корзина очищена');
    }

    /**
     * Remove a specific item from the cart.
     */
    public function removeItem(Cart $cart, CartItem $item): RedirectResponse
    {
        if ($item->cart_id !== $cart->id) {
            abort(404);
        }

        $item->delete();

        return redirect()
            ->back()
            ->with('success', 'Товар удалён из корзины');
    }

    /**
     * Update quantity of a specific item in the cart.
     */
    public function updateItemQuantity(Request $request, Cart $cart, CartItem $item): RedirectResponse
    {
        if ($item->cart_id !== $cart->id) {
            abort(404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item->update([
            'quantity' => $validated['quantity'],
        ]);

        return redirect()
            ->back()
            ->with('success', 'Количество обновлено');
    }

    /**
     * Calculate price for a product based on user.
     */
    public function calculatePrice(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'user_id' => 'nullable|exists:users,id',
            'currency_code' => 'nullable|string|exists:currencies,code',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $user = $validated['user_id'] ? User::find($validated['user_id']) : null;
        $currencyCode = $validated['currency_code'] ?? 'RUB';
        
        // 1. Get price in base currency (with user discounts applied)
        if ($user) {
            $basePrice = $this->priceService->getDiscountedPrice($product, $user);
        } else {
            $basePrice = (float) $product->base_price;
        }

        // 2. Convert to target currency
        $finalPrice = $basePrice;
        if ($currencyCode !== 'RUB') {
             $currency = \App\Models\Currency::where('code', $currencyCode)->first();
             if ($currency) {
                 $finalPrice = $this->priceService->convertPrice($basePrice, $currency);
             }
        }

        return response()->json([
            'price' => round($finalPrice, 2),
            'currency_code' => $currencyCode,
        ]);
    }

    /**
     * Search users for async selector.
     */
    public function searchUsers(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query', '');
        
        $users = User::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'label' => "{$user->name} ({$user->email})",
                ];
            });
            
        return response()->json($users);
    }
}
