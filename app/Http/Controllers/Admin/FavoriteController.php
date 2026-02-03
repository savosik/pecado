<?php

namespace App\Http\Controllers\Admin;

use App\Models\Favorite;
use Illuminate\Http\Request;
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
                    'name' => $favorite->user->name,
                    'email' => $favorite->user->email,
                ] : null,
                'product' => $favorite->product ? [
                    'id' => $favorite->product->id,
                    'name' => $favorite->product->name,
                    'image_url' => $favorite->product->getFirstMediaUrl('main'),
                ] : null,
            ];
        });

        return Inertia::render('Admin/Pages/Favorites/Index', [
            'favorites' => $favorites,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
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
}
