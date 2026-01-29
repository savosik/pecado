<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeliveryAddressController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DeliveryAddress::class);

        // DeliveryAddressScope automatically filters by user_id for non-admins
        $addresses = DeliveryAddress::paginate(15);

        return response()->json($addresses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', DeliveryAddress::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string',
        ]);

        $validated['user_id'] = $request->user()->id;

        $address = DeliveryAddress::create($validated);

        return response()->json([
            'message' => 'Delivery address created successfully',
            'delivery_address' => $address,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(DeliveryAddress $deliveryAddress): JsonResponse
    {
        Gate::authorize('view', $deliveryAddress);

        return response()->json($deliveryAddress);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, DeliveryAddress $deliveryAddress): JsonResponse
    {
        Gate::authorize('update', $deliveryAddress);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string',
        ]);

        $deliveryAddress->update($validated);

        return response()->json([
            'message' => 'Delivery address updated successfully',
            'delivery_address' => $deliveryAddress,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DeliveryAddress $deliveryAddress): JsonResponse
    {
        Gate::authorize('delete', $deliveryAddress);

        $deliveryAddress->delete();

        return response()->json([
            'message' => 'Delivery address deleted successfully',
        ]);
    }
}
