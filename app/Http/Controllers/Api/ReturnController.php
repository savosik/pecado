<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductReturnResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReturnController extends Controller
{
    /**
     * Display a listing of the returns.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProductReturn::class);

        $query = ProductReturn::with(['items.product', 'items.order']);

        // Admins see all; regular users see only their own
        if (!$request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }

        $returns = $query->latest()->paginate(15);

        return ProductReturnResource::collection($returns);
    }

    /**
     * Store a newly created return.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ProductReturn::class);

        $validated = $request->validate([
            'comment' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.order_id' => 'required|integer|exists:orders,id',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.reason' => ['required', 'string', Rule::enum(ReturnReason::class)],
            'items.*.reason_comment' => 'nullable|string|max:500',
        ]);

        // Validate that each item belongs to user's order
        foreach ($validated['items'] as $item) {
            $order = Order::find($item['order_id']);
            
            if (!$order) {
                return response()->json([
                    'message' => 'Order not found',
                    'errors' => ['items' => ["Order ID {$item['order_id']} not found"]],
                ], 422);
            }

            // Check if order belongs to user (unless admin)
            if ($order->user_id !== $request->user()->id && !$request->user()->is_admin) {
                return response()->json([
                    'message' => 'Unauthorized',
                    'errors' => ['items' => ["Order ID {$item['order_id']} does not belong to you"]],
                ], 403);
            }

            // Check if the product was actually ordered in this order
            $orderItem = OrderItem::where('order_id', $item['order_id'])
                ->where('product_id', $item['product_id'])
                ->first();

            if (!$orderItem) {
                return response()->json([
                    'message' => 'Product not found in order',
                    'errors' => ['items' => ["Product ID {$item['product_id']} was not found in Order ID {$item['order_id']}"]],
                ], 422);
            }

            // Check if requested quantity doesn't exceed ordered quantity
            if ($item['quantity'] > $orderItem->quantity) {
                return response()->json([
                    'message' => 'Quantity exceeds ordered amount',
                    'errors' => ['items' => ["Cannot return {$item['quantity']} items; only {$orderItem->quantity} were ordered"]],
                ], 422);
            }
        }

        $return = DB::transaction(function () use ($request, $validated) {
            $productReturn = ProductReturn::create([
                'user_id' => $request->user()->id,
                'status' => ReturnStatus::PENDING,
                'comment' => $validated['comment'] ?? null,
                'total_amount' => 0,
            ]);

            $totalAmount = 0;

            foreach ($validated['items'] as $item) {
                // Get the price from the original order item
                $orderItem = OrderItem::where('order_id', $item['order_id'])
                    ->where('product_id', $item['product_id'])
                    ->first();

                $price = $orderItem->price;
                $subtotal = $price * $item['quantity'];
                $totalAmount += $subtotal;

                $productReturn->items()->create([
                    'order_id' => $item['order_id'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'reason' => $item['reason'],
                    'reason_comment' => $item['reason_comment'] ?? null,
                    'price' => $price,
                    'subtotal' => $subtotal,
                ]);
            }

            $productReturn->update(['total_amount' => $totalAmount]);

            return $productReturn->load(['items.product', 'items.order']);
        });

        return response()->json([
            'message' => 'Return created successfully',
            'return' => new ProductReturnResource($return),
        ], 201);
    }

    /**
     * Display the specified return.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $return = ProductReturn::with(['items.product', 'items.order'])->find($id);

        if (!$return) {
            abort(404);
        }

        Gate::authorize('view', $return);

        return response()->json([
            'return' => new ProductReturnResource($return),
        ]);
    }

    /**
     * Update the specified return (admin only - for status changes).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $return = ProductReturn::find($id);

        if (!$return) {
            abort(404);
        }

        Gate::authorize('update', $return);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(ReturnStatus::class)],
            'admin_comment' => 'nullable|string|max:1000',
        ]);

        $return->update($validated);

        return response()->json([
            'message' => 'Return updated successfully',
            'return' => new ProductReturnResource($return->load(['items.product', 'items.order'])),
        ]);
    }

    /**
     * Remove the specified return (admin only).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $return = ProductReturn::find($id);

        if (!$return) {
            abort(404);
        }

        Gate::authorize('delete', $return);

        $return->delete();

        return response()->json([
            'message' => 'Return deleted successfully',
        ]);
    }
}
