<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Country;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Region;
use App\Models\User;
use App\Models\ClientStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class UserController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = User::query()
            ->with(['region', 'currency', 'roles'])
            ->withCount('companies');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Фильтры
        if ($request->filled('region_id')) {
            $query->where('region_id', $request->input('region_id'));
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn($q) => $q->where('name', $request->input('role')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $users = $query->paginate($request->input('per_page', 15));

        // Подсчёт пользователей по статусам (без фильтров)
        $statusCounts = [];
        foreach (UserStatus::cases() as $status) {
            $statusCounts[$status->value] = User::where('status', $status->value)->count();
        }
        $statusCounts['all'] = User::count();

        return Inertia::render('Admin/Pages/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'region_id', 'role', 'status', 'sort_by', 'sort_order', 'per_page']),
            'statuses' => collect(UserStatus::cases())->map(fn($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'statusCounts' => $statusCounts,
            'availableRoles' => Role::orderBy('name')->pluck('name')->toArray(),
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
            'statuses' => collect(UserStatus::cases())->map(fn($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'availableRoles' => Role::orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name]),
            'clientStatuses' => ClientStatus::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'currency_id' => 'nullable|exists:currencies,id',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'status' => 'nullable|string|in:' . implode(',', array_column(UserStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'erp_id' => 'nullable|string|max:255|unique:users,erp_id',
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
            'client_status_id' => 'nullable|exists:client_statuses,id',
        ]);

        $roles = $validated['roles'] ?? [];
        unset($validated['roles']);

        $user = User::create($validated);
        $user->syncRoles($roles);

        return $this->redirectAfterSave($request, 'admin.users.index', 'admin.users.edit', $user, 'Пользователь успешно создан');
    }

    public function edit(User $user)
    {
        $user->load(['region', 'currency', 'companies', 'deliveryAddresses', 'questionnaire', 'roles', 'clientStatus']);

        return Inertia::render('Admin/Pages/Users/Edit', [
            'user' => array_merge($user->toArray(), [
                'role_names' => $user->getRoleNames()->toArray(),
            ]),
            'regions' => Region::select('id', 'name')->orderBy('name')->get(),
            'currencies' => Currency::select('id', 'code', 'name')->orderBy('code')->get(),
            'countries' => collect(Country::cases())->map(fn($country) => [
                'value' => $country->value,
                'label' => $country->label(),
            ]),
            'statuses' => collect(UserStatus::cases())->map(fn($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'availableRoles' => Role::orderBy('name')->get()->map(fn($r) => ['id' => $r->id, 'name' => $r->name]),
            'clientStatuses' => ClientStatus::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8', // Опционально при обновлении
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'currency_id' => 'nullable|exists:currencies,id',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'status' => 'nullable|string|in:' . implode(',', array_column(UserStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'erp_id' => 'nullable|string|max:255|unique:users,erp_id,' . $user->id,
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
            'client_status_id' => 'nullable|exists:client_statuses,id',
        ]);

        $roles = $validated['roles'] ?? [];
        unset($validated['roles']);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        if (
            isset($validated['status']) &&
            $validated['status'] === UserStatus::ACTIVE->value &&
            empty($validated['name'] ?? $user->name)
        ) {
            return back()->withErrors([
                'status' => 'Невозможно активировать пользователя без заполненного имени. Пользователь ещё не прошёл онбординг.',
            ]);
        }

        $user->update($validated);
        $user->syncRoles($roles);

        return $this->redirectAfterSave($request, 'admin.users.index', 'admin.users.edit', $user, 'Пользователь успешно обновлен');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'Пользователь успешно удален');
    }

    /**
     * Async search endpoint для EntitySelector
     */
    public function search(Request $request)
    {
        $query = User::query();

        if ($search = $request->input('query', $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->select('id', 'name', 'email', 'phone')
            ->limit(20)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ];
            });

        return response()->json($users);
    }
}
