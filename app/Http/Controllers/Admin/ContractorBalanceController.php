<?php

namespace App\Http\Controllers\Admin;

use App\Models\ContractorBalance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class ContractorBalanceController extends Controller
{
    public function index(Request $request)
    {
        $query = ContractorBalance::query()
            ->with(['user', 'company']);

        // Поиск по пользователю / ИНН / компании
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('contractor_inn', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('surname', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('company', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('legal_name', 'like', "%{$search}%");
                  });
            });
        }

        // Фильтр по пользователю
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Сортировка
        $sortBy    = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowed   = ['id', 'contractor_inn', 'current_balance', 'overdue_debt', 'balance_erp_updated_at'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage  = $request->input('per_page', 15);
        $balances = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/ContractorBalances/Index', [
            'balances' => $balances,
            'filters'  => $request->only(['search', 'user_id', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function show(ContractorBalance $contractorBalance)
    {
        $contractorBalance->load(['user', 'company', 'overdueDetails']);

        return Inertia::render('Admin/Pages/ContractorBalances/Show', [
            'balance' => $contractorBalance,
        ]);
    }

    public function destroy(ContractorBalance $contractorBalance)
    {
        $contractorBalance->delete();

        return redirect()
            ->route('admin.contractor-balances.index')
            ->with('success', 'Баланс контрагента успешно удалён');
    }

    public function search(Request $request)
    {
        $query = ContractorBalance::query()->with(['user', 'company']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('contractor_inn', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $balances = $query->limit(20)->get()->map(function ($balance) {
            return [
                'id'             => $balance->id,
                'name'           => $balance->user->full_name . ' (' . $balance->contractor_inn . ')',
                'contractor_inn' => $balance->contractor_inn,
                'current_balance' => $balance->current_balance,
            ];
        });

        return response()->json($balances);
    }
}
