<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FavoriteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Favorite::class);

        // Non-admins see only their own favorites
        $query = Favorite::with('product');
        
        if (!$request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }

        $favorites = $query->paginate(15);

        return response()->json($favorites);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Favorite::class);

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $validated['user_id'] = $request->user()->id;

        // Check if already exists
        $existing = Favorite::where('user_id', $validated['user_id'])
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Product is already in favorites',
                'favorite' => $existing->load('product'),
            ], 200);
        }

        $favorite = Favorite::create($validated);

        return response()->json([
            'message' => 'Product added to favorites',
            'favorite' => $favorite->load('product'),
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Favorite $favorite): JsonResponse
    {
        Gate::authorize('delete', $favorite);

        $favorite->delete();

        return response()->json([
            'message' => 'Product removed from favorites',
        ]);
    }
}
