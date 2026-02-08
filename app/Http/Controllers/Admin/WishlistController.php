<?php

namespace App\Http\Controllers\Admin;

use App\Models\WishlistItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class WishlistController extends AdminController
{
    /**
     * Display a listing of wishlist items.
     */
    public function index(Request $request): Response
    {
        $query = WishlistItem::query()
            ->with(['user', 'product.media']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('product', function ($productQuery) use ($search) {
                    $productQuery->where('name', 'like', "%{$search}%");
                });
            });
        }

        // Фильтр по пользователю
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        // Фильтр по товару
        if ($productId = $request->input('product_id')) {
            $query->where('product_id', $productId);
        }

        // Фильтр по дате (от)
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        // Фильтр по дате (до)
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $wishlistItems = $query->paginate($perPage)->withQueryString();

        // Transform data for frontend
        $wishlistItems->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'created_at' => $item->created_at?->format('d.m.Y H:i'),
                'user' => $item->user ? [
                    'id' => $item->user->id,
                    'name' => $item->user->full_name,
                    'email' => $item->user->email,
                ] : null,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'image_url' => $item->product->getFirstMediaUrl('main'),
                ] : null,
            ];
        });

        // Получаем данные для фильтров
        $userFilter = null;
        if ($userId = $request->input('user_id')) {
            $user = User::find($userId);
            if ($user) {
                $userFilter = [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'label' => "{$user->full_name} ({$user->email})",
                ];
            }
        }

        $productFilter = null;
        if ($productId = $request->input('product_id')) {
            $product = \App\Models\Product::with('media')->find($productId);
            if ($product) {
                $productFilter = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'image_url' => $product->getFirstMediaUrl('main'),
                ];
            }
        }

        return Inertia::render('Admin/Pages/Wishlist/Index', [
            'wishlistItems' => $wishlistItems,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
                'user_id' => $request->input('user_id'),
                'user' => $userFilter,
                'product_id' => $request->input('product_id'),
                'product' => $productFilter,
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ],
        ]);
    }

    /**
     * Remove the specified wishlist item from storage.
     */
    public function destroy(WishlistItem $wishlistItem): RedirectResponse
    {
        $wishlistItem->delete();

        return redirect()
            ->route('admin.wishlist.index')
            ->with('success', 'Запись списка желаний успешно удалена');
    }

    /**
     * Show the form for creating a new wishlist item.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Wishlist/Create');
    }

    /**
     * Store a newly created wishlist item in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
        ], [
            'user_id.required' => 'Пользователь обязателен',
            'user_id.exists' => 'Выбранный пользователь не существует',
            'product_id.required' => 'Товар обязателен',
            'product_id.exists' => 'Выбранный товар не существует',
        ]);

        // Проверяем, нет ли уже такой записи
        $exists = WishlistItem::where('user_id', $validated['user_id'])
            ->where('product_id', $validated['product_id'])
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors(['product_id' => 'Этот товар уже в списке желаний у данного пользователя']);
        }

        WishlistItem::create($validated);

        return redirect()
            ->route('admin.wishlist.index')
            ->with('success', 'Запись списка желаний успешно создана');
    }

    /**
     * Show the form for editing the specified wishlist item.
     */
    public function edit(WishlistItem $wishlist): Response
    {
        $wishlist->load(['user', 'product.media']);

        return Inertia::render('Admin/Pages/Wishlist/Edit', [
            'wishlistItem' => [
                'id' => $wishlist->id,
                'user_id' => $wishlist->user_id,
                'product_id' => $wishlist->product_id,
                'user' => $wishlist->user ? [
                    'id' => $wishlist->user->id,
                    'name' => $wishlist->user->full_name,
                    'email' => $wishlist->user->email,
                    'label' => "{$wishlist->user->full_name} ({$wishlist->user->email})",
                ] : null,
                'product' => $wishlist->product ? [
                    'id' => $wishlist->product->id,
                    'name' => $wishlist->product->name,
                    'sku' => $wishlist->product->sku,
                    'image_url' => $wishlist->product->getFirstMediaUrl('main'),
                ] : null,
            ],
        ]);
    }

    /**
     * Update the specified wishlist item in storage.
     */
    public function update(Request $request, WishlistItem $wishlist): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
        ], [
            'user_id.required' => 'Пользователь обязателен',
            'user_id.exists' => 'Выбранный пользователь не существует',
            'product_id.required' => 'Товар обязателен',
            'product_id.exists' => 'Выбранный товар не существует',
        ]);

        // Проверяем, нет ли уже такой записи (исключая текущую)
        $exists = WishlistItem::where('user_id', $validated['user_id'])
            ->where('product_id', $validated['product_id'])
            ->where('id', '!=', $wishlist->id)
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors(['product_id' => 'Этот товар уже в списке желаний у данного пользователя']);
        }

        $wishlist->update($validated);

        return redirect()
            ->route('admin.wishlist.index')
            ->with('success', 'Запись списка желаний успешно обновлена');
    }

    /**
     * Bulk delete wishlist items.
     */
    public function bulkDelete(Request $request): RedirectResponse
    {
        $request->validate([
            'wishlist_ids' => 'required|array',
            'wishlist_ids.*' => 'integer|exists:wishlist_items,id',
        ]);

        WishlistItem::whereIn('id', $request->input('wishlist_ids'))->delete();

        return redirect()
            ->route('admin.wishlist.index')
            ->with('success', 'Выбранные записи списка желаний успешно удалены');
    }

    /**
     * Search users for async selector.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $query = $request->input('query', '');
        
        $users = User::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('surname', 'like', "%{$query}%")
                  ->orWhere('patronymic', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->select('id', 'name', 'surname', 'patronymic', 'email')
            ->orderBy('surname')
            ->limit(20)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'label' => "{$user->full_name} ({$user->email})",
                ];
            });
            
        return response()->json($users);
    }
}
