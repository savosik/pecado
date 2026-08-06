<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\DefectType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Справочник типовых дефектов глазами склада.
 *
 * Тот же справочник, что и в /admin (Admin\DefectTypeController), но доступный
 * начальнику склада: роли `warehouse-head` в админку намеренно закрыт вход,
 * а формулировки дефектов ведёт именно склад. Права отдельные —
 * `wms-defect-types.*`, чтобы кладовщик справочник только использовал.
 */
class DefectTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Wms/Pages/DefectTypes/Index', [
            'types' => DefectType::query()->ordered()->get()->map(fn (DefectType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'is_active' => $type->is_active,
                'sort_order' => $type->sort_order,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:defect_types,name',
        ], [
            'name.required' => 'Введите формулировку дефекта.',
            'name.unique' => 'Такой дефект уже есть в справочнике.',
        ]);

        DefectType::create([
            'name' => $validated['name'],
            'is_active' => true,
            'sort_order' => (int) DefectType::max('sort_order') + 1,
        ]);

        return back()->with('success', 'Дефект добавлен в справочник.');
    }

    public function update(Request $request, DefectType $defectType): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:defect_types,name,'.$defectType->id,
            'is_active' => 'boolean',
        ], [
            'name.required' => 'Введите формулировку дефекта.',
            'name.unique' => 'Такой дефект уже есть в справочнике.',
        ]);

        $defectType->update([
            'name' => $validated['name'],
            'is_active' => $request->boolean('is_active', $defectType->is_active),
        ]);

        return back()->with('success', 'Справочник обновлён.');
    }

    public function destroy(DefectType $defectType): RedirectResponse
    {
        $defectType->delete();

        return back()->with('success', 'Дефект удалён из справочника.');
    }
}
