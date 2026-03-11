<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class UserBalanceController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = UserBalance::query()
            ->with(['user']);

        // Поиск по имени пользователя
        if ($search = $request->input('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%")
                    ->orWhere('patronymic', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Фильтр по пользователю
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $balances = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/UserBalances/Index', [
            'balances' => $balances,
            'filters' => $request->only(['search', 'user_id', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/UserBalances/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:user_balances,user_id',
            'balance' => 'required|numeric',
            'overdue_debt' => 'nullable|numeric|min:0',
        ], [
            'user_id.unique' => 'У этого пользователя уже есть баланс.',
        ]);

        $balance = UserBalance::create($validated);

        return $this->redirectAfterSave($request, 'admin.user-balances.index', 'admin.user-balances.edit', $balance, 'Баланс успешно создан');
    }

    public function edit(UserBalance $userBalance)
    {
        $userBalance->load(['user']);

        return Inertia::render('Admin/Pages/UserBalances/Edit', [
            'balance' => $userBalance,
        ]);
    }

    public function update(Request $request, UserBalance $userBalance)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:user_balances,user_id,' . $userBalance->id,
            'balance' => 'required|numeric',
            'overdue_debt' => 'nullable|numeric|min:0',
        ], [
            'user_id.unique' => 'У этого пользователя уже есть баланс.',
        ]);

        $userBalance->update($validated);

        return $this->redirectAfterSave($request, 'admin.user-balances.index', 'admin.user-balances.edit', $userBalance, 'Баланс успешно обновлён');
    }

    public function destroy(UserBalance $userBalance)
    {
        $userBalance->delete();

        return redirect()->route('admin.user-balances.index')->with('success', 'Баланс успешно удалён');
    }

    public function search(Request $request)
    {
        $query = UserBalance::query()->with(['user']);

        if ($search = $request->input('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%")
                    ->orWhere('patronymic', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $balances = $query->limit(20)
            ->get()
            ->map(function ($balance) {
                return [
                    'id' => $balance->id,
                    'name' => "{$balance->user->full_name} ({$balance->balance})",
                    'user_name' => $balance->user->full_name,
                    'balance' => $balance->balance,
                ];
            });

        return response()->json($balances);
    }
}
