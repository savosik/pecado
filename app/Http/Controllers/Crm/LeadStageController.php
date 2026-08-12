<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmLead;
use App\Models\CrmLeadStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Стадии воронки лидов — их задаёт руководитель отдела (crm-27).
 *
 * Менеджер двигает лида по воронке, но не переписывает саму воронку: это
 * разные полномочия, и второе живёт под отдельным правом.
 */
class LeadStageController extends CrmController
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $stage = CrmLeadStage::create([
            ...$data,
            'position' => $data['position'] ?? ((int) CrmLeadStage::query()->max('position') + 1),
        ]);

        return response()->json(['data' => $stage], 201);
    }

    public function update(Request $request, CrmLeadStage $stage): JsonResponse
    {
        $stage->update($this->validated($request));

        return response()->json(['data' => $stage->fresh()]);
    }

    /**
     * Удалить стадию.
     *
     * Стадию с лидами удалять нельзя: каскад молча потерял бы историю,
     * а лиды остались бы вне воронки. Предлагаем перенести их.
     */
    public function destroy(CrmLeadStage $stage): JsonResponse
    {
        $leads = CrmLead::query()->where('stage_id', $stage->getKey())->count();

        if ($leads > 0) {
            throw ValidationException::withMessages([
                'stage' => "На стадии «{$stage->name}» ещё {$leads} лид(ов). Перенесите их, прежде чем удалять стадию.",
            ]);
        }

        $stage->delete();

        return response()->json(['data' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'color' => ['sometimes', Rule::in(['gray', 'blue', 'green', 'red', 'purple', 'orange', 'yellow', 'teal'])],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Введите название стадии.',
            'color.in' => 'Такой цвет не поддерживается.',
        ]);

        // Стадия не может быть одновременно выигрышной и проигрышной: конверсия
        // считается именно по этим флагам, и такой лид попал бы в обе половины
        // сразу, а сумма долей перестала бы сходиться к целому.
        if (($data['is_won'] ?? false) && ($data['is_lost'] ?? false)) {
            throw ValidationException::withMessages([
                'is_won' => 'Стадия не может быть одновременно выигрышной и проигрышной.',
            ]);
        }

        return $data;
    }
}
