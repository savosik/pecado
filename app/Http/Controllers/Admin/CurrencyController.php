<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class CurrencyController extends Controller
{
    use RedirectsAfterSave;

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
            'official_rate' => 'nullable|numeric|min:0',
            'rate_coefficient' => 'nullable|numeric|min:0',
            'exchange_rate' => 'required|numeric|min:0',
            'exchange_rate_date' => 'nullable|date',
        ]);

        // Если это базовая валюта, сбросить флаг у всех других
        if ($validated['is_base'] ?? false) {
            Currency::where('is_base', true)->update(['is_base' => false]);
        }

        $currency = Currency::create($validated);

        return $this->redirectAfterSave($request, 'admin.currencies.index', 'admin.currencies.edit', $currency, 'Валюта успешно создана');
    }

    public function show(Currency $currency)
    {
        return Inertia::render('Admin/Pages/Currencies/Show', [
            'currency' => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'is_base' => $currency->is_base,
                'official_rate' => $currency->official_rate,
                'rate_coefficient' => $currency->rate_coefficient,
                'exchange_rate' => $currency->exchange_rate,
                'exchange_rate_date' => $currency->exchange_rate_date?->format('d.m.Y'),
                'created_at' => $currency->created_at?->format('d.m.Y H:i'),
                'updated_at' => $currency->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
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
            'code' => 'required|string|max:10|unique:currencies,code,'.$currency->id,
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10',
            'is_base' => 'boolean',
            'official_rate' => 'nullable|numeric|min:0',
            'rate_coefficient' => 'nullable|numeric|min:0',
            'exchange_rate' => 'required|numeric|min:0',
            'exchange_rate_date' => 'nullable|date',
        ]);

        // Если это базовая валюта, сбросить флаг у всех других
        if ($validated['is_base'] ?? false) {
            Currency::where('is_base', true)
                ->where('id', '!=', $currency->id)
                ->update(['is_base' => false]);
        }

        $currency->update($validated);

        return $this->redirectAfterSave($request, 'admin.currencies.index', 'admin.currencies.edit', $currency, 'Валюта успешно обновлена');
    }

    public function destroy(Currency $currency)
    {
        $currency->delete();

        return redirect()->route('admin.currencies.index')->with('success', 'Валюта успешно удалена');
    }

    /**
     * Обновить курсы валют из ЦБ РФ вручную (для ручного тестирования).
     *
     * Примечание: в production курсы поступают из 1С через RabbitMQ (US-04).
     * Эта кнопка позволяет обновить курсы вручную из ЦБ РФ без ожидания события из 1С.
     */
    public function updateRates(Request $request)
    {
        try {
            $exitCode = \Artisan::call('currency:update');

            if ($exitCode === 0) {
                return redirect()
                    ->route('admin.currencies.index')
                    ->with('success', 'Курсы валют успешно обновлены из ЦБ РФ.');
            }

            return redirect()
                ->route('admin.currencies.index')
                ->with('error', 'Не удалось обновить курсы. Проверьте доступность ЦБ РФ и логи.');

        } catch (\Exception $e) {
            return redirect()
                ->route('admin.currencies.index')
                ->with('error', 'Ошибка при обновлении курсов: '.$e->getMessage());
        }
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
}
