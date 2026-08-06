<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Country;
use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Controllers\Controller;
use App\Models\ClientStatus;
use App\Models\PersonalManager;
use App\Models\Region;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = User::query()
            ->with(['region', 'region.currency', 'roles'])
            ->withCount('companies');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    // Рабочее наименование из 1С: клиент мог переименовать себя
                    // в кабинете, а искать его продолжают по карточке партнёра.
                    ->orWhere('erp_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Фильтры
        if ($request->filled('region_id')) {
            $query->where('region_id', $request->input('region_id'));
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->input('role')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('user_kind')) {
            $query->where('user_kind', $request->input('user_kind'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $users = $query->paginate($request->input('per_page', 15));

        $users->through(function ($user) {
            $user->temporary_password = $user->must_change_password ? $user->temporary_password : null;

            return $user;
        });

        // Подсчёт пользователей по статусам (без фильтров)
        $statusCounts = [];
        foreach (UserStatus::cases() as $status) {
            $statusCounts[$status->value] = User::where('status', $status->value)->count();
        }
        $statusCounts['all'] = User::count();

        return Inertia::render('Admin/Pages/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'region_id', 'role', 'status', 'user_kind', 'sort_by', 'sort_order', 'per_page']),
            'userKinds' => UserKind::options(),
            'userKindCounts' => $this->userKindCounts(),
            'statuses' => collect(UserStatus::cases())->map(fn ($status) => [
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
            'regions' => Region::select('id', 'name')->with('currency:id,code,name')->orderBy('name')->get(),
            'countries' => collect(Country::cases())->map(fn ($country) => [
                'value' => $country->value,
                'label' => $country->label(),
            ]),
            'statuses' => collect(UserStatus::cases())->map(fn ($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'userKinds' => UserKind::options(),
            'availableRoles' => Role::orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'clientStatuses' => ClientStatus::select('id', 'name')->orderBy('name')->get(),
            'personalManagers' => PersonalManager::select('id', 'name')->orderBy('name')->get(),
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
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'status' => 'nullable|string|in:'.implode(',', array_column(UserStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'user_kind' => 'nullable|string|in:'.implode(',', array_column(UserKind::cases(), 'value')),
            'erp_id' => 'nullable|string|max:255|unique:users,erp_id',
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
            'client_status_id' => 'nullable|exists:client_statuses,id',
            'personal_manager_id' => 'nullable|exists:personal_managers,id',
            'send_welcome_email' => 'sometimes|boolean',
        ]);

        $roles = $validated['roles'] ?? [];
        $sendWelcomeEmail = (bool) ($validated['send_welcome_email'] ?? false);
        unset($validated['roles'], $validated['send_welcome_email']);

        $user = User::create($validated);
        $user->syncRoles($roles);
        $this->syncKindWithRoles($user, $roles, $request->filled('user_kind'));

        if ($sendWelcomeEmail) {
            \App\Events\UserRegisteredOnSite::dispatch($user, 'admin');
        }

        return $this->redirectAfterSave($request, 'admin.users.index', 'admin.users.edit', $user, 'Пользователь успешно создан');
    }

    public function show(User $user)
    {
        $user->load(['region', 'region.currency', 'companies', 'deliveryAddresses', 'questionnaire', 'roles', 'clientStatus', 'personalManager']);

        return Inertia::render('Admin/Pages/Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'country' => $user->country,
                'city' => $user->city,
                'status' => $user->status,
                'user_kind' => $user->user_kind->value,
                'user_kind_label' => $user->user_kind->label(),
                'comment' => $user->comment,
                'erp_id' => $user->erp_id,
                'erp_name' => $user->erp_name,
                'is_subscribed' => $user->is_subscribed,
                'created_at' => $user->created_at?->format('d.m.Y H:i'),
                'updated_at' => $user->updated_at?->format('d.m.Y H:i'),
                'region' => $user->region ? ['id' => $user->region->id, 'name' => $user->region->name] : null,
                'roles' => $user->roles->pluck('name')->toArray(),
                'companies_count' => $user->companies->count(),
                'delivery_addresses_count' => $user->deliveryAddresses->count(),
                'client_status' => $user->clientStatus ? ['id' => $user->clientStatus->id, 'name' => $user->clientStatus->name] : null,
                'personal_manager' => $user->personalManager ? ['id' => $user->personalManager->id, 'name' => $user->personalManager->name] : null,
                'has_questionnaire' => $user->questionnaire !== null,
            ],
        ]);
    }

    public function edit(User $user)
    {
        $user->load(['region', 'region.currency', 'companies', 'deliveryAddresses', 'questionnaire', 'roles', 'clientStatus', 'personalManager']);

        return Inertia::render('Admin/Pages/Users/Edit', [
            'user' => array_merge($user->toArray(), [
                'role_names' => $user->getRoleNames()->toArray(),
                'temporary_password' => $user->must_change_password ? $user->temporary_password : null,
            ]),
            'regions' => Region::select('id', 'name')->with('currency:id,code,name')->orderBy('name')->get(),
            'countries' => collect(Country::cases())->map(fn ($country) => [
                'value' => $country->value,
                'label' => $country->label(),
            ]),
            'statuses' => collect(UserStatus::cases())->map(fn ($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
            'userKinds' => UserKind::options(),
            'availableRoles' => Role::orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name]),
            'clientStatuses' => ClientStatus::select('id', 'name')->orderBy('name')->get(),
            'personalManagers' => PersonalManager::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:8', // Опционально при обновлении
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'country' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'status' => 'nullable|string|in:'.implode(',', array_column(UserStatus::cases(), 'value')),
            'comment' => 'nullable|string',
            'user_kind' => 'nullable|string|in:'.implode(',', array_column(UserKind::cases(), 'value')),
            'erp_id' => 'nullable|string|max:255|unique:users,erp_id,'.$user->id,
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
            'client_status_id' => 'nullable|exists:client_statuses,id',
            'personal_manager_id' => 'nullable|exists:personal_managers,id',
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
        $this->syncKindWithRoles($user, $roles, $request->filled('user_kind'));

        return $this->redirectAfterSave($request, 'admin.users.index', 'admin.users.edit', $user, 'Пользователь успешно обновлен');
    }

    /**
     * Назначили роль — значит сотрудник, если тип не выбран руками.
     *
     * Без этого история повторилась бы на первом же новом закупщике: колонка
     * user_kind по умолчанию 'client', и он снова оказался бы в CRM среди
     * клиентов. Явный выбор администратора в форме не трогаем — бывают
     * клиентские учётки со служебной ролью для интеграции.
     *
     * @param  list<string>  $roles
     */
    private function syncKindWithRoles(User $user, array $roles, bool $kindChosenExplicitly): void
    {
        if ($kindChosenExplicitly || $roles === [] || $user->user_kind !== UserKind::CLIENT) {
            return;
        }

        $user->update(['user_kind' => UserKind::STAFF->value]);
    }

    /**
     * Сколько пользователей каждого типа — для счётчиков на кнопках фильтра.
     *
     * @return array<string, int>
     */
    private function userKindCounts(): array
    {
        return User::query()
            ->selectRaw('user_kind, count(*) as total')
            ->groupBy('user_kind')
            ->pluck('total', 'user_kind')
            ->map(fn ($total): int => (int) $total)
            ->all();
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
