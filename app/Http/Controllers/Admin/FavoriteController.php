<?php

namespace App\Http\Controllers\Admin;

use App\Models\Favorite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class FavoriteController extends AdminController
{
    /**
     * Display a listing of favorites.
     */
    public function index(Request $request): Response
    {
        $query = Favorite::query()
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

        $favorites = $query->paginate($perPage)->withQueryString();

        // Transform data for frontend
        $favorites->getCollection()->transform(function ($favorite) {
            return [
                'id' => $favorite->id,
                'created_at' => $favorite->created_at?->format('d.m.Y H:i'),
                'user' => $favorite->user ? [
                    'id' => $favorite->user->id,
                    'name' => $favorite->user->full_name,
                    'email' => $favorite->user->email,
                ] : null,
                'product' => $favorite->product ? [
                    'id' => $favorite->product->id,
                    'name' => $favorite->product->name,
                    'image_url' => $favorite->product->getFirstMediaUrl('main'),
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

        return Inertia::render('Admin/Pages/Favorites/Index', [
            'favorites' => $favorites,
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
     * Remove the specified favorite from storage.
     */
    public function destroy(Favorite $favorite): RedirectResponse
    {
        $favorite->delete();

        return redirect()
            ->route('admin.favorites.index')
            ->with('success', 'Запись избранного успешно удалена');
    }

    /**
     * Show the form for creating a new favorite.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Favorites/Create');
    }

    /**
     * Store a newly created favorite in storage.
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
        $exists = Favorite::where('user_id', $validated['user_id'])
            ->where('product_id', $validated['product_id'])
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors(['product_id' => 'Этот товар уже в избранном у данного пользователя']);
        }

        Favorite::create($validated);

        return redirect()
            ->route('admin.favorites.index')
            ->with('success', 'Запись избранного успешно создана');
    }

    /**
     * Show the form for editing the specified favorite.
     */
    public function edit(Favorite $favorite): Response
    {
        $favorite->load(['user', 'product.media']);

        return Inertia::render('Admin/Pages/Favorites/Edit', [
            'favorite' => [
                'id' => $favorite->id,
                'user_id' => $favorite->user_id,
                'product_id' => $favorite->product_id,
                'user' => $favorite->user ? [
                    'id' => $favorite->user->id,
                    'name' => $favorite->user->full_name,
                    'email' => $favorite->user->email,
                    'label' => "{$favorite->user->full_name} ({$favorite->user->email})",
                ] : null,
                'product' => $favorite->product ? [
                    'id' => $favorite->product->id,
                    'name' => $favorite->product->name,
                    'sku' => $favorite->product->sku,
                    'image_url' => $favorite->product->getFirstMediaUrl('main'),
                ] : null,
            ],
        ]);
    }

    /**
     * Update the specified favorite in storage.
     */
    public function update(Request $request, Favorite $favorite): RedirectResponse
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
        $exists = Favorite::where('user_id', $validated['user_id'])
            ->where('product_id', $validated['product_id'])
            ->where('id', '!=', $favorite->id)
            ->exists();

        if ($exists) {
            return redirect()
                ->back()
                ->withErrors(['product_id' => 'Этот товар уже в избранном у данного пользователя']);
        }

        $favorite->update($validated);

        return redirect()
            ->route('admin.favorites.index')
            ->with('success', 'Запись избранного успешно обновлена');
    }

    /**
     * Bulk delete favorites.
     */
    public function bulkDelete(Request $request): RedirectResponse
    {
        $request->validate([
            'favorite_ids' => 'required|array',
            'favorite_ids.*' => 'integer|exists:favorites,id',
        ]);

        Favorite::whereIn('id', $request->input('favorite_ids'))->delete();

        return redirect()
            ->route('admin.favorites.index')
            ->with('success', 'Выбранные записи избранного успешно удалены');
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
