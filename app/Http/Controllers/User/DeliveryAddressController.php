<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DeliveryAddressController extends Controller
{
    public function index()
    {
        $addresses = DeliveryAddress::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('User/Cabinet/DeliveryAddresses/Index', [
            'addresses' => $addresses,
        ]);
    }

    public function create()
    {
        return Inertia::render('User/Cabinet/DeliveryAddresses/Form', [
            'address' => null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateAddress($request);
        $validated['user_id'] = Auth::id();

        $address = DeliveryAddress::create($validated);

        return redirect()->route('cabinet.delivery-addresses.edit', $address)
            ->with('success', 'Адрес доставки успешно создан.');
    }

    public function edit(DeliveryAddress $deliveryAddress)
    {
        $this->authorizeAddress($deliveryAddress);

        return Inertia::render('User/Cabinet/DeliveryAddresses/Form', [
            'address' => $deliveryAddress,
        ]);
    }

    public function update(Request $request, DeliveryAddress $deliveryAddress)
    {
        $this->authorizeAddress($deliveryAddress);

        $validated = $this->validateAddress($request);
        $deliveryAddress->update($validated);

        return back()->with('success', 'Адрес доставки успешно обновлён.');
    }

    public function destroy(Request $request, DeliveryAddress $deliveryAddress)
    {
        $this->authorizeAddress($deliveryAddress);
        $deliveryAddress->delete();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('cabinet.delivery-addresses.index')
            ->with('success', 'Адрес доставки успешно удалён.');
    }

    private function authorizeAddress(DeliveryAddress $deliveryAddress): void
    {
        abort_if($deliveryAddress->user_id !== Auth::id(), 403, 'Доступ запрещён.');
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'address_data' => ['nullable', 'array'],
        ], [
            'name.required' => 'Название обязательно.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'address.required' => 'Адрес обязателен.',
        ]);
    }
}
