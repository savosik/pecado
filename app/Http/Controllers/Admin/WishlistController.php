<?php

namespace App\Http\Controllers\Admin;

use App\Models\WishlistItem;
use Illuminate\Http\Request;
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
                    'name' => $item->user->name,
                    'email' => $item->user->email,
                ] : null,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'image_url' => $item->product->getFirstMediaUrl('main'),
                ] : null,
            ];
        });

        return Inertia::render('Admin/Pages/Wishlist/Index', [
            'wishlistItems' => $wishlistItems,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
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
}
