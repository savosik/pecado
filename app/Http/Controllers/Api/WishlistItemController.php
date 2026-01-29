<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WishlistItemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WishlistItem::class);

        // Non-admins see only their own wishlist items
        $query = WishlistItem::with('product');
        
        if (!$request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }

        $wishlistItems = $query->paginate(15);

        return response()->json($wishlistItems);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', WishlistItem::class);

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $validated['user_id'] = $request->user()->id;

        // Check if already exists
        $existing = WishlistItem::where('user_id', $validated['user_id'])
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Product is already in wishlist',
                'wishlist_item' => $existing->load('product'),
            ], 200);
        }

        $wishlistItem = WishlistItem::create($validated);

        return response()->json([
            'message' => 'Product added to wishlist',
            'wishlist_item' => $wishlistItem->load('product'),
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WishlistItem $wishlistItem): JsonResponse
    {
        Gate::authorize('delete', $wishlistItem);

        $wishlistItem->delete();

        return response()->json([
            'message' => 'Product removed from wishlist',
        ]);
    }
}
