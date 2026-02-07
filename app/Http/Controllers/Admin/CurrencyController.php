<?php

namespace App\Http\Controllers\Admin;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

class CurrencyController extends Controller
{
    public function index(Request $request)
    {
        $query = Currency::query();

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('symbol', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'code');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $currencies = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Currencies/Index', [
            'currencies' => $currencies,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Currencies/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10|unique:currencies,code',
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10',
            'is_base' => 'boolean',
            'exchange_rate' => 'required|numeric|min:0',
            'correction_factor' => 'nullable|numeric|min:0',
        ]);

        // Если это базовая валюта, сбросить флаг у всех других
        if ($validated['is_base'] ?? false) {
            Currency::where('is_base', true)->update(['is_base' => false]);
        }

        Currency::create($validated);

        return redirect()
            ->route('admin.currencies.index')
            ->with('success', 'Валюта успешно создана');
    }

    public function edit(Currency $currency)
    {
        return Inertia::render('Admin/Pages/Currencies/Edit', [
            'currency' => $currency,
        ]);
    }

    public function update(Request $request, Currency $currency)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:10|unique:currencies,code,' . $currency->id,
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10',
            'is_base' => 'boolean',
            'exchange_rate' => 'required|numeric|min:0',
            'correction_factor' => 'nullable|numeric|min:0',
        ]);

        // Если это базовая валюта, сбросить флаг у всех других
        if ($validated['is_base'] ?? false) {
            Currency::where('is_base', true)
                ->where('id', '!=', $currency->id)
                ->update(['is_base' => false]);
        }

        $currency->update($validated);

        return redirect()
            ->route('admin.currencies.index')
            ->with('success', 'Валюта успешно обновлена');
    }

    public function destroy(Currency $currency)
    {
        $currency->delete();

        return redirect()
            ->route('admin.currencies.index')
            ->with('success', 'Валюта успешно удалена');
    }

    public function search(Request $request)
    {
        $query = Currency::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('symbol', 'like', "%{$search}%");
            });
        }

        $currencies = $query->select('id', 'code', 'name', 'symbol')
            ->limit(20)
            ->get()
            ->map(function ($currency) {
                return [
                    'id' => $currency->id,
                    'name' => "{$currency->code} - {$currency->name} ({$currency->symbol})",
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                ];
            });

        return response()->json($currencies);
    }

    public function updateRates()
    {
        try {
            Artisan::call('currency:update');
            $output = Artisan::output();

            return redirect()
                ->route('admin.currencies.index')
                ->with('success', 'Курсы валют успешно обновлены');
        } catch (\Exception $e) {
            return redirect()
                ->route('admin.currencies.index')
                ->with('error', 'Ошибка обновления курсов: ' . $e->getMessage());
        }
    }
}
