<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeliveryAddressController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = DeliveryAddress::query()
            ->with('user');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        // Фильтры
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $deliveryAddresses = $query->paginate($request->input('per_page', 15));

        return Inertia::render('Admin/Pages/DeliveryAddresses/Index', [
            'deliveryAddresses' => $deliveryAddresses,
            'filters' => $request->only(['search', 'user_id', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/DeliveryAddresses/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'address_data' => 'nullable|array',
        ]);

        $deliveryAddress = DeliveryAddress::create($validated);

        return $this->redirectAfterSave($request, 'admin.delivery-addresses.index', 'admin.delivery-addresses.edit', $deliveryAddress, 'Адрес доставки успешно создан');
    }

    public function show(DeliveryAddress $deliveryAddress)
    {
        $deliveryAddress->load('user');

        return Inertia::render('Admin/Pages/DeliveryAddresses/Show', [
            'deliveryAddress' => [
                'id' => $deliveryAddress->id,
                'name' => $deliveryAddress->name,
                'address' => $deliveryAddress->address,
                'address_data' => $deliveryAddress->address_data,
                'user' => $deliveryAddress->user ? [
                    'id' => $deliveryAddress->user->id,
                    'name' => $deliveryAddress->user->name,
                    'email' => $deliveryAddress->user->email,
                ] : null,
                'created_at' => $deliveryAddress->created_at?->format('d.m.Y H:i'),
                'updated_at' => $deliveryAddress->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    public function edit(DeliveryAddress $deliveryAddress)
    {
        $deliveryAddress->load('user');

        return Inertia::render('Admin/Pages/DeliveryAddresses/Edit', [
            'deliveryAddress' => $deliveryAddress,
        ]);
    }

    public function update(Request $request, DeliveryAddress $deliveryAddress)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'address_data' => 'nullable|array',
        ]);

        $deliveryAddress->update($validated);

        return $this->redirectAfterSave($request, 'admin.delivery-addresses.index', 'admin.delivery-addresses.edit', $deliveryAddress, 'Адрес доставки успешно обновлен');
    }

    public function destroy(DeliveryAddress $deliveryAddress)
    {
        $deliveryAddress->delete();

        return redirect()->route('admin.delivery-addresses.index')->with('success', 'Адрес доставки успешно удален');
    }

    /**
     * Async search endpoint для EntitySelector
     */
    public function search(Request $request)
    {
        $query = DeliveryAddress::query()->with('user');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $addresses = $query->limit(20)
            ->get()
            ->map(function ($address) {
                return [
                    'id' => $address->id,
                    'name' => $address->name.' ('.$address->user?->name.')',
                    'address' => $address->address,
                    'user_name' => $address->user?->name,
                ];
            });

        return response()->json($addresses);
    }
}
