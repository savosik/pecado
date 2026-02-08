<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Region;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()
            ->with(['region', 'currency'])
            ->withCount('companies');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%");
            });
        }

        // Фильтры
        if ($request->filled('region_id')) {
            $query->where('region_id', $request->input('region_id'));
        }

        if ($request->filled('is_admin')) {
            $query->where('is_admin', $request->boolean('is_admin'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $users = $query->paginate($request->input('per_page', 15));

        return Inertia::render('Admin/Pages/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'region_id', 'is_admin', 'status', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Users/Create', [
            'regions' => Region::select('id', 'name')->orderBy('name')->get(),
            'currencies' => Currency::select('id', 'code', 'name')->orderBy('code')->get(),
            'countries' => collect(Country::cases())->map(fn($country) => [
                'value' => $country->value,
                'label' => $country->label(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'surname' => 'nullable|string|max:255',
            'patronymic' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => ['nullable', 'string', 'max:255', 'regex:/^\+7\(\d{3}\)\d{3}-\d{2}-\d{2}$|^\+375\(\d{2}\)\d{3}-\d{2}-\d{2}$/'],
            'country' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'currency_id' => 'nullable|exists:currencies,id',
            'is_admin' => 'boolean',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'status' => 'nullable|string|max:255',
            'comment' => 'nullable|string',
            'erp_id' => 'nullable|string|max:255|unique:users,erp_id',
        ]);

        $user = User::create($validated);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Пользователь успешно создан');
    }

    public function edit(User $user)
    {
        $user->load(['region', 'currency', 'companies', 'deliveryAddresses']);

        return Inertia::render('Admin/Pages/Users/Edit', [
            'user' => $user,
            'regions' => Region::select('id', 'name')->orderBy('name')->get(),
            'currencies' => Currency::select('id', 'code', 'name')->orderBy('code')->get(),
            'countries' => collect(Country::cases())->map(fn($country) => [
                'value' => $country->value,
                'label' => $country->label(),
            ]),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'surname' => 'nullable|string|max:255',
            'patronymic' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8', // Опционально при обновлении
            'phone' => ['nullable', 'string', 'max:255', 'regex:/^\+7\(\d{3}\)\d{3}-\d{2}-\d{2}$|^\+375\(\d{2}\)\d{3}-\d{2}-\d{2}$/'],
            'country' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'currency_id' => 'nullable|exists:currencies,id',
            'is_admin' => 'boolean',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'status' => 'nullable|string|max:255',
            'comment' => 'nullable|string',
            'erp_id' => 'nullable|string|max:255|unique:users,erp_id,' . $user->id,
        ]);

        // Если пароль не указан, удаляем его из массива обновления
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Пользователь успешно обновлен');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Пользователь успешно удален');
    }

    /**
     * Async search endpoint для EntitySelector
     */
    public function search(Request $request)
    {
        $query = User::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%")
                    ->orWhere('patronymic', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->select('id', 'name', 'surname', 'patronymic', 'email', 'phone')
            ->limit(20)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ];
            });

        return response()->json($users);
    }
}
