<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Shortage\ShortageReasonCategory;
use App\Models\ShortageReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Справочник причин недоборов — его ведёт руководитель отдела (short-02).
 *
 * Менеджер выбирает причину из списка, но сам список не дописывает: иначе
 * в сводке разведутся «нет остатка», «Нет остатков» и «остаток кончился»,
 * и разрез по причинам перестанет что-либо значить.
 *
 * Категория причины остаётся перечислением в коде
 * ({@see ShortageReasonCategory}) — на ней держатся чипы, цвета и легенда.
 */
class ShortageReasonController extends CrmController
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $reason = ShortageReason::query()->create([
            ...$data,
            'sort_order' => $data['sort_order']
                ?? ((int) ShortageReason::query()->max('sort_order') + 10),
        ]);

        // refresh(), а не сам объект: is_active и is_system приходят из умолчаний
        // таблицы, и без перечитывания экран получил бы вместо них null.
        return response()->json(['data' => $reason->refresh()->toOption()], 201);
    }

    public function update(Request $request, ShortageReason $reason): JsonResponse
    {
        $reason->update($this->validated($request, $reason));

        return response()->json(['data' => $reason->refresh()->toOption()]);
    }

    /**
     * Удалить причину.
     *
     * Размеченные ею строки удалять или обнулять нельзя — это разбор прошлых
     * месяцев, и сводки за них должны сходиться. Такую причину отключают.
     * Заводские причины не удаляются никогда: на них ссылается перенос старой
     * разметки, и восстановить их из интерфейса будет нечем.
     */
    public function destroy(ShortageReason $reason): JsonResponse
    {
        if ($reason->is_system) {
            throw ValidationException::withMessages([
                'reason' => "«{$reason->name}» — заводская причина, её можно только отключить.",
            ]);
        }

        $used = $reason->orderItems()->count();

        if ($used > 0) {
            throw ValidationException::withMessages([
                'reason' => "Причиной «{$reason->name}» размечено {$used} строк(и) — отключите её вместо удаления.",
            ]);
        }

        $reason->delete();

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ShortageReason $reason = null): array
    {
        return $request->validate([
            'name' => [
                $reason === null ? 'required' : 'sometimes',
                'string',
                'min:3',
                'max:191',
                Rule::unique('shortage_reasons', 'name')->ignore($reason?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => [
                $reason === null ? 'required' : 'sometimes',
                Rule::enum(ShortageReasonCategory::class),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Введите формулировку причины.',
            'name.min' => 'Формулировка причины короче трёх символов ничего не объяснит.',
            'name.unique' => 'Такая причина уже есть в справочнике.',
            'description.max' => 'Пояснение не длиннее 500 символов.',
            'category.required' => 'Выберите категорию причины.',
        ]);
    }
}
