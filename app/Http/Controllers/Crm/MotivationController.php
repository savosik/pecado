<?php

namespace App\Http\Controllers\Crm;

use App\Models\PayrollCalculation;
use App\Services\Motivation\Dto\MotivationInputs;
use App\Services\Motivation\MotivationPresenter;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\PayrollScopeResolver;
use App\Services\Payroll\Support\Money;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Мой месяц» — рабочее место работника по Положению о мотивации 2.2 (mot-26).
 *
 * Страница и polling-ответ собираются одним методом: цифры на экране и в фоновом
 * обновлении обязаны совпадать. Снимок — тот же payroll_calculations, что
 * у «Моей зарплаты»; разница в схеме, по которой он посчитан, и в способе показа.
 *
 * Право — crm-motivation.view, отдельное от crm-salary: раздел не показывается
 * менеджерам до ввода Положения в действие (решение заказчика от 08.09.2026).
 */
class MotivationController extends CrmController
{
    private const MONTHS_BACK = 12;

    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PayrollCalculationService $calculations,
        private readonly MotivationPresenter $presenter,
        private readonly PayrollCalculator $calculator,
        private readonly PayrollParamsResolver $paramsResolver,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Index', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * Перечень, из которого сложилась строка: партнёры, позиции, накладные.
     */
    public function evidence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'line' => ['required', 'string', 'in:base_sales,new_partners,focus,overdue'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'manager' => ['nullable', 'integer', 'min:1'],
        ], [
            'line.required' => 'Не указана строка расчёта.',
            'line.in' => 'Такой строки в расчёте нет.',
        ]);

        $snapshot = $this->snapshot($request);

        if ($snapshot === null) {
            return response()->json(['message' => 'Расчёт для этого работника не ведётся.'], 422);
        }

        return response()->json($this->presenter->evidence($snapshot, (string) $data['line']));
    }

    /**
     * Калькулятор «что если»: четыре ползунка считаются тем же калькулятором.
     *
     * Параметры берутся из снимка, а не действующие: ползунки сравниваются с цифрой,
     * которая уже стоит на экране, и посчитана она именно этими параметрами.
     */
    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'manager' => ['nullable', 'integer', 'min:1'],
            'base_revenue' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'new_partners_revenue' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'focus_revenue' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'overdue_integral' => ['required', 'numeric', 'min:0', 'max:999999999999999'],
        ], [
            'base_revenue.required' => 'Не переданы отгрузки базы.',
            'new_partners_revenue.required' => 'Не переданы отгрузки новым партнёрам.',
            'focus_revenue.required' => 'Не переданы отгрузки фокус-перечня.',
            'overdue_integral.required' => 'Не передана база вычета.',
        ]);

        $snapshot = $this->snapshot($request);

        if ($snapshot === null) {
            return response()->json(['message' => 'Расчёт для этого работника не ведётся.'], 422);
        }

        $inputs = PayrollInputs::fromArray((array) $snapshot->inputs);
        $stored = (array) ($snapshot->params_effective ?? []);
        $params = $stored === []
            ? $this->paramsResolver->effective((int) $snapshot->personal_manager_id, CarbonImmutable::parse($inputs->month))
            : EffectiveParams::fromArray($stored);

        $motivation = ($inputs->motivation ?? new MotivationInputs)->toArray();

        $hypothetical = $this->calculator->calculate($params, $inputs->with(['motivation' => array_replace($motivation, [
            'base_revenue' => (float) $data['base_revenue'],
            'new_partners_revenue' => (float) $data['new_partners_revenue'],
            'focus_revenue' => (float) $data['focus_revenue'],
            'overdue_integral' => (float) $data['overdue_integral'],
        ])]));

        // Точка отсчёта — тот же путь расчёта на фактических значениях: сравнивать
        // с итогом снимка нельзя, у него могли быть другие параметры.
        $baseline = $this->calculator->calculate($params, $inputs);
        $variable = $hypothetical->component('motivation_variable');

        return response()->json([
            'total' => Money::round($hypothetical->total),
            'baseline_total' => Money::round($baseline->total),
            'delta' => Money::round($hypothetical->total - $baseline->total),
            'variable_part' => $variable === null ? null : [
                'amount' => (float) $variable->amount,
                'p1' => (float) ($variable->meta['p1'] ?? 0),
                'p2' => (float) ($variable->meta['p2'] ?? 0),
                'p3' => (float) ($variable->meta['p3'] ?? 0),
                'k1' => (float) ($variable->meta['k1'] ?? 0),
                'capped' => (bool) ($variable->meta['capped'] ?? false),
                'floored' => (bool) ($variable->meta['floored'] ?? false),
                'threshold' => $variable->meta['threshold'] ?? null,
            ],
        ]);
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
            'is_current_month' => $month->equalTo(CarbonImmutable::now()->startOfMonth()),
            'months' => $this->months(),
            'manager' => $manager === null ? null : ['id' => (int) $manager->getKey(), 'name' => (string) $manager->name],
            'participates' => $manager === null ? null : (bool) $manager->payroll_enabled,
            'scope_options' => $this->scopes->options($actor),
            'can_see_all' => $this->scopes->seesAll($actor),
            'can_edit' => $actor->can('crm-motivation.edit'),
            'calculation' => null,
            'poll_seconds' => max(15, (int) config('payroll.poll_seconds', 60)),
            'server_time' => now()->toIso8601String(),
        ];

        if ($manager === null || ! $manager->payroll_enabled) {
            return $payload;
        }

        $calculation = $this->calculations->ensureDraft((int) $manager->getKey(), $month);
        $payload['calculation'] = $this->presenter->present($calculation);

        return $payload;
    }

    private function snapshot(Request $request): ?PayrollCalculation
    {
        $actor = $this->crmActor($request);
        $manager = $this->scopes->manager($actor, $request->integer('manager') ?: null);

        if ($manager === null || ! $manager->payroll_enabled) {
            return null;
        }

        return $this->calculations->ensureDraft((int) $manager->getKey(), $this->month($request));
    }

    private function month(Request $request): CarbonImmutable
    {
        // input(), а не query(): калькулятор приходит POST-ом с месяцем в теле.
        $raw = (string) $request->input('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01');

            if ($month !== null && $month->lte(CarbonImmutable::now()->startOfMonth())) {
                return $month->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function months(): array
    {
        $rows = [];
        $cursor = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < self::MONTHS_BACK; $i++) {
            $rows[] = ['value' => $cursor->format('Y-m'), 'label' => MonthLabel::ru($cursor)];
            $cursor = $cursor->subMonth();
        }

        return $rows;
    }
}
