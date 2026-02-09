<?php

namespace App\Http\Controllers\Admin;

use App\Models\Currency;
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
            ->with(['user', 'currency']);

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

        // Фильтр по валюте
        if ($request->filled('currency_id')) {
            $query->where('currency_id', $request->input('currency_id'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $balances = $query->paginate($perPage)->withQueryString();

        // Получаем список валют для фильтра
        $currencies = Currency::select('id', 'code', 'name')->get();

        return Inertia::render('Admin/Pages/UserBalances/Index', [
            'balances' => $balances,
            'currencies' => $currencies,
            'filters' => $request->only(['search', 'user_id', 'currency_id', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        $currencies = Currency::select('id', 'code', 'name', 'symbol')->get();

        return Inertia::render('Admin/Pages/UserBalances/Create', [
            'currencies' => $currencies,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'currency_id' => 'required|exists:currencies,id',
            'balance' => 'required|numeric',
            'overdue_debt' => 'nullable|numeric|min:0',
        ]);

        $balance = UserBalance::create($validated);

        return $this->redirectAfterSave($request, 'admin.user-balances.index', 'admin.user-balances.edit', $balance, 'Баланс успешно создан');
    }

    public function edit(UserBalance $userBalance)
    {
        $userBalance->load(['user', 'currency']);
        $currencies = Currency::select('id', 'code', 'name', 'symbol')->get();

        return Inertia::render('Admin/Pages/UserBalances/Edit', [
            'balance' => $userBalance,
            'currencies' => $currencies,
        ]);
    }

    public function update(Request $request, UserBalance $userBalance)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'currency_id' => 'required|exists:currencies,id',
            'balance' => 'required|numeric',
            'overdue_debt' => 'nullable|numeric|min:0',
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
        $query = UserBalance::query()->with(['user', 'currency']);

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
                    'name' => "{$balance->user->full_name} - {$balance->currency->code} ({$balance->balance})",
                    'user_name' => $balance->user->full_name,
                    'currency_code' => $balance->currency->code,
                    'balance' => $balance->balance,
                ];
            });

        return response()->json($balances);
    }
}
