<?php

namespace App\Http\Controllers\Crm;

use App\Events\Payroll\PayrollInputsChanged;
use App\Http\Requests\Crm\StorePayrollAdjustmentRequest;
use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Services\Payroll\PayrollSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Ручные строки дохода: позиции доп. дохода и корректировки РОПа.
 *
 * Строка — с автором и основанием, поэтому правки нет: ошибся — удали и заведи
 * заново, след останется в журнале. Замороженный месяц не трогается.
 */
class SalaryAdjustmentController extends CrmController
{
    public function __construct(private readonly PayrollSettingsService $settings) {}

    public function store(StorePayrollAdjustmentRequest $request): JsonResponse
    {
        $managerId = (int) $request->integer('manager_id');
        $month = CarbonImmutable::createFromFormat('Y-m-d', $request->input('month').'-01')->startOfMonth();

        if ($this->isFrozen($managerId, $month)) {
            return response()->json(['message' => 'Расчёт за этот месяц утверждён — сначала переоткройте его.'], 422);
        }

        $qty = $request->filled('qty') ? (float) $request->input('qty') : 1.0;
        $price = (float) $request->input('price');

        PayrollManualAdjustment::query()->create([
            'personal_manager_id' => $managerId,
            'period_month' => $month,
            'component_key' => (string) $request->input('component'),
            'label' => (string) $request->input('label'),
            'qty' => $qty,
            'price' => $price,
            'amount' => round($qty * $price, 2),
            'comment' => $request->input('comment') !== null ? (string) $request->input('comment') : null,
            'author_id' => $this->crmActor($request)->getKey(),
        ]);

        PayrollInputsChanged::dispatch([$managerId], 'adjustment.created', [$month->toDateString()]);

        return response()->json([
            'saved' => true,
            'adjustments' => $this->settings->adjustments($month),
        ]);
    }

    public function destroy(PayrollManualAdjustment $adjustment): JsonResponse
    {
        $managerId = (int) $adjustment->personal_manager_id;
        $month = CarbonImmutable::instance($adjustment->period_month)->startOfMonth();

        if ($this->isFrozen($managerId, $month)) {
            return response()->json(['message' => 'Расчёт за этот месяц утверждён — сначала переоткройте его.'], 422);
        }

        $adjustment->delete();

        PayrollInputsChanged::dispatch([$managerId], 'adjustment.deleted', [$month->toDateString()]);

        return response()->json([
            'deleted' => true,
            'adjustments' => $this->settings->adjustments($month),
        ]);
    }

    private function isFrozen(int $managerId, CarbonImmutable $month): bool
    {
        $latest = PayrollCalculation::query()
            ->forManager($managerId)
            ->forPeriod($month)
            ->orderByDesc('version')
            ->first();

        return $latest !== null && $latest->isFrozen();
    }
}
