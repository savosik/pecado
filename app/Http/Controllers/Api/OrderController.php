<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Order\CheckoutServiceInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function __construct(
        protected CheckoutServiceInterface $checkoutService,
        protected OrderRepositoryInterface $orderRepository
    ) {}

    /**
     * Display a listing of the user's orders.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $orders = $this->orderRepository->getPaginatedForUser($request->user());

        return response()->json($orders);
    }

    /**
     * Store a newly created order from a cart.
     */
    public function checkout(Request $request): JsonResponse
    {
        Gate::authorize('create', Order::class);

        $validated = $request->validate([
            'cart_id' => 'required|integer|exists:carts,id',
            'company_id' => 'required|integer|exists:companies,id',
            'delivery_address_id' => 'required|integer|exists:delivery_addresses,id',
            'comment' => 'nullable|string',
        ]);

        $cart = Cart::findOrFail($validated['cart_id']);
        $company = Company::findOrFail($validated['company_id']);
        $address = DeliveryAddress::findOrFail($validated['delivery_address_id']);

        // Check if cart belongs to user
        if ($cart->user_id !== $request->user()->id && !$request->user()->is_admin) {
            abort(403);
        }

        // Check if there are items in the cart
        if ($cart->items()->count() === 0) {
            return response()->json(['message' => 'Cart is empty'], 422);
        }

        try {
            $order = $this->checkoutService->checkout(
                $cart,
                $company,
                $address,
                $validated['comment'] ?? null
            );
        } catch (\App\Exceptions\InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->getItems(),
            ], 422);
        }

        // Optionally clear the cart after checkout if needed, 
        // but often we just leave it or mark as processed.
        // For now, we leave it since it's linked to the order.

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order->load('items', 'children.items'),
        ], 201);
    }

    /**
     * Display the specified order.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = $this->orderRepository->findWithRelations($id, ['items', 'company', 'deliveryAddress']);

        if (!$order) {
            abort(404);
        }

        Gate::authorize('view', $order);

        return response()->json([
            'order' => $order,
        ]);
    }
}
