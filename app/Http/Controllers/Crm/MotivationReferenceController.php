<?php

namespace App\Http\Controllers\Crm;

use App\Services\Motivation\FocusListService;
use App\Services\Motivation\PlanReferenceService;
use App\Services\Motivation\QuarterReferenceService;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollScopeResolver;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Справочные экраны работника: «Фокус-товары», «Откуда мой план», «Премия отдела» (mot-30).
 *
 * Отвечают на вопросы «что продвигать», «почему мой план такой» и «сколько
 * отделу осталось до премии». Все числа — с сервера, из тех же источников,
 * что расчёт.
 */
class MotivationReferenceController extends CrmController
{
    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PayrollCalculationService $calculations,
        private readonly FocusListService $focus,
        private readonly PlanReferenceService $plan,
        private readonly QuarterReferenceService $quarter,
    ) {}

    public function focus(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Focus', $this->payload($request, 'focus'));
    }

    public function focusData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'focus'));
    }

    public function plan(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Plan', $this->payload($request, 'plan'));
    }

    public function planData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'plan'));
    }

    public function quarter(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Quarter', $this->payload($request, 'quarter'));
    }

    public function quarterData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'quarter'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $screen): array
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
            'data' => null,
        ];

        // Премия отдела — общая: работник без карточки её тоже видит.
        if ($screen === 'quarter') {
            $payload['data'] = $this->quarter->build($month);

            return $payload;
        }

        if ($manager === null) {
            return $payload;
        }

        $managerId = (int) $manager->getKey();

        $payload['data'] = match ($screen) {
            'plan' => $this->plan->build($managerId, $month),
            default => $this->focus->build(
                $managerId,
                $month,
                $manager->payroll_enabled ? $this->calculations->ensureDraft($managerId, $month) : null,
            ),
        };

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
