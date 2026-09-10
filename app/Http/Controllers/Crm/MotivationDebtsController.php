<?php

namespace App\Http\Controllers\Crm;

use App\Services\Motivation\DebtListService;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollScopeResolver;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Долги: во что они обходятся» (mot-28).
 *
 * Читает снимок расчёта — тот же, что «Мой месяц»: сумма «вычтено за месяц»
 * по партнёрам обязана равняться показателю К1, и это возможно только когда
 * оба экрана смотрят в одни данные.
 */
class MotivationDebtsController extends CrmController
{
    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PayrollCalculationService $calculations,
        private readonly DebtListService $debts,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Debts', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $actor = $this->crmActor($request);
        $month = $this->month($request);
        $manager = $this->scopes->manager($actor, $request->integer('manager') ?: null);

        $payload = [
            'month' => $month->format('Y-m'),
            'month_label' => MonthLabel::ru($month),
            'manager' => $manager === null ? null : ['id' => (int) $manager->getKey(), 'name' => (string) $manager->name],
            'scope_options' => $this->scopes->options($actor),
            'can_see_all' => $this->scopes->seesAll($actor),
            'focus_partner' => $request->integer('partner') ?: null,
            'debts' => null,
        ];

        if ($manager === null || ! $manager->payroll_enabled) {
            return $payload;
        }

        $calculation = $this->calculations->ensureDraft((int) $manager->getKey(), $month);
        $payload['debts'] = $this->debts->build($calculation);

        return $payload;
    }

    private function month(Request $request): CarbonImmutable
    {
        $raw = (string) $request->input('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01');

            if ($month !== null && $month->lte(CarbonImmutable::now()->startOfMonth())) {
                return $month->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }
}
