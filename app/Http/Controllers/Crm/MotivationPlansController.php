<?php

namespace App\Http\Controllers\Crm;

use App\Models\ManagerAbsence;
use App\Models\Motivation\MotivationPlanOrder;
use App\Models\PersonalManager;
use App\Services\Motivation\PlanCalculator;
use App\Services\Motivation\PlanOrderService;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Мастер планов на квартал (mot-33; п. 5 Положения).
 *
 * Расчёт по формуле, сравнение с действующим планом, вето на повышение
 * по результату, ограничитель снижения, утверждение приказа. Утверждение
 * записывает значения в планы продаж — единственное место, откуда план
 * читают все экраны CRM.
 */
class MotivationPlansController extends CrmController
{
    public function __construct(
        private readonly PlanCalculator $calculator,
        private readonly PlanOrderService $orders,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Plans', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * Посчитать черновики приказов по всем работникам (или одному).
     */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quarter' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'manager' => ['nullable', 'integer', 'exists:personal_managers,id'],
            'waive_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'quarter.required' => 'Не указан квартал.',
            'quarter.regex' => 'Квартал — в формате ГГГГ-ММ.',
        ]);

        $quarter = $this->quarter($data['quarter']);
        $actor = $this->crmActor($request);
        $waive = isset($data['waive_reason']) && trim((string) $data['waive_reason']) !== '' ? trim((string) $data['waive_reason']) : null;

        foreach ($this->managers(isset($data['manager']) ? (int) $data['manager'] : null) as $manager) {
            $existing = $this->approvedOrder((int) $manager->getKey(), $quarter);

            if ($existing !== null) {
                continue;   // утверждённый квартал пересчитывается только новой версией
            }

            $this->orders->draft((int) $manager->getKey(), $quarter, [], $actor, $waive);
        }

        return response()->json($this->payload($request));
    }

    /**
     * Ручная правка значений черновика с обоснованием.
     */
    public function override(Request $request, MotivationPlanOrder $order): JsonResponse
    {
        $data = $request->validate([
            'values' => ['required', 'array', 'size:3'],
            'values.*' => ['required', 'numeric', 'min:0'],
            'comment' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'values.required' => 'Не переданы значения плана.',
            'values.size' => 'План задаётся на три месяца квартала.',
            'comment.required' => 'Отклонение от расчётного значения требует обоснования.',
            'comment.min' => 'Обоснование слишком короткое.',
        ]);

        try {
            $this->orders->override($order, array_map('floatval', (array) $data['values']), (string) $data['comment']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $this->payload($request->merge(['quarter' => $order->quarter_start->format('Y-m')])));
    }

    /**
     * Утвердить приказ: значения уходят в планы продаж.
     */
    public function approve(Request $request, MotivationPlanOrder $order): JsonResponse
    {
        try {
            $approved = $this->orders->approve($order, $this->crmActor($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => sprintf('План на квартал с %s утверждён и записан в планы продаж.', $approved->quarter_start->format('m.Y')),
        ] + $this->payload($request->merge(['quarter' => $order->quarter_start->format('Y-m')])));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $quarter = $this->quarter((string) $request->input('quarter', ''));
        $rows = [];
        $calculatedTotal = 0.0;
        $currentTotal = 0.0;

        foreach ($this->managers(null) as $manager) {
            $managerId = (int) $manager->getKey();
            $order = $this->approvedOrder($managerId, $quarter)
                ?? $this->latestOrder($managerId, $quarter);

            $calculated = $this->calculator->calculate($managerId, $quarter, [], $order?->decline_limit_waived_reason !== null);
            $values = $order === null ? $calculated['values'] : (array) $order->values;

            $months = [];
            foreach ($calculated['values'] as $key => $value) {
                $current = $calculated['previous_values'][$key] ?? null;
                $months[] = [
                    'month' => (string) $key,
                    'working_days' => (int) ($calculated['working_days'][$key] ?? 0),
                    'seasonal' => (float) ($calculated['seasonal'][$key] ?? 1.0),
                    'calculated' => Money::round((float) $value),
                    'value' => Money::round((float) ($values[$key] ?? $value)),
                    'current_plan' => $current === null ? null : Money::round((float) $current),
                ];
                $calculatedTotal += (float) ($values[$key] ?? $value);
                $currentTotal += (float) ($current ?? 0);
            }

            $rows[] = [
                'id' => $managerId,
                'name' => (string) $manager->name,
                'order' => $order === null ? null : [
                    'id' => (int) $order->getKey(),
                    'version' => (int) $order->version,
                    'status' => $order->status,
                    'approved' => $order->status === MotivationPlanOrder::STATUS_APPROVED,
                    'approved_at' => $order->approved_at?->toIso8601String(),
                    'comment' => $order->comment,
                    'decline_limited' => (bool) $order->decline_limited,
                    'waived_reason' => $order->decline_limit_waived_reason,
                    'manual' => $order->comment !== null && $order->status === MotivationPlanOrder::STATUS_DRAFT,
                ],
                'median_per_day' => $calculated['median_per_day'],
                'days_counted' => $calculated['sample']['days'],
                'days_excluded_absence' => $calculated['sample']['excluded_days'],
                'zero_days' => $calculated['sample']['zero_days'],
                'sample_from' => $calculated['sample']['from'],
                'sample_to' => $calculated['sample']['to'],
                'growth_rate' => $calculated['growth_rate'],
                'overperformance_carry' => $calculated['overperformance_carry'],
                'previous_quarter_total' => $calculated['previous_quarter_total'],
                'previous_quarter_comparable' => $calculated['previous_quarter_comparable'],
                'decline_limited' => $calculated['decline_limited'],
                'months' => $months,
                'total' => Money::round(array_sum(array_column($months, 'value'))),
                'current_total' => Money::round(array_sum(array_map(fn (array $m): float => (float) ($m['current_plan'] ?? 0), $months))),
                'warnings' => $this->warnings($calculated, $quarter),
            ];
        }

        return [
            'quarter' => $quarter->toDateString(),
            'quarter_label' => $this->quarterLabel($quarter),
            'quarters' => $this->quarters(),
            'managers' => $rows,
            'comparison' => [
                'calculated_total' => Money::round($calculatedTotal),
                'current_total' => Money::round($currentTotal),
                'delta_percent' => $currentTotal > 0 ? round($calculatedTotal / $currentTotal - 1, 4) : null,
            ],
            'timesheet_empty' => ! ManagerAbsence::query()
                ->whereDate('starts_on', '<=', $quarter->subDay())
                ->whereDate('ends_on', '>=', $quarter->subMonths(6))
                ->exists(),
            'decline_limit' => (float) config('motivation.default_parameters.plan_decline_limit', 0.2),
            'can_edit' => $this->crmActor($request)->can('crm-motivation.edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $calculated
     * @return list<string>
     */
    private function warnings(array $calculated, CarbonImmutable $quarter): array
    {
        $warnings = [];
        $sample = $calculated['sample'];

        if ($sample['days'] > 0 && $sample['zero_days'] / $sample['days'] > 0.25) {
            $warnings[] = sprintf('Больше четверти дней выборки без отгрузок (%d из %d): проверьте полноту данных периода.', $sample['zero_days'], $sample['days']);
        }

        if ($calculated['decline_limited']) {
            $warnings[] = 'Применён предел снижения: расчётная база ниже плана прошлого квартала более чем на допустимую долю.';
        }

        if ($calculated['previous_quarter_total'] !== null && ! $calculated['previous_quarter_comparable']) {
            $warnings[] = 'Предел снижения не применяется: план прошлого квартала поставлен не по этой методике.';
        }

        if ($quarter->lte(CarbonImmutable::now()->startOfQuarter())) {
            $warnings[] = 'Квартал уже начался: план утверждается до его начала одним решением (п. 5.3).';
        }

        return $warnings;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PersonalManager>
     */
    private function managers(?int $only)
    {
        return PersonalManager::query()
            ->active()->where('payroll_enabled', true)
            ->when($only !== null, fn ($q) => $q->whereKey($only))
            ->orderBy('name')
            ->get();
    }

    private function approvedOrder(int $managerId, CarbonImmutable $quarter): ?MotivationPlanOrder
    {
        return $this->latestOrder($managerId, $quarter, MotivationPlanOrder::STATUS_APPROVED);
    }

    /**
     * Последняя версия приказа: сначала id, затем строка — сортировка строк
     * с JSON-колонками валит MySQL «Out of sort memory».
     */
    private function latestOrder(int $managerId, CarbonImmutable $quarter, ?string $status = null): ?MotivationPlanOrder
    {
        $id = MotivationPlanOrder::query()
            ->forQuarter($quarter)
            ->where('personal_manager_id', $managerId)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('version')
            ->value('id');

        return $id === null ? null : MotivationPlanOrder::query()->find($id);
    }

    private function quarter(string $raw): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01');

            if ($month !== null) {
                return $month->startOfQuarter();
            }
        }

        return CarbonImmutable::now()->addQuarter()->startOfQuarter();
    }

    private function quarterLabel(CarbonImmutable $quarter): string
    {
        return sprintf('%s квартал %d', ['I', 'II', 'III', 'IV'][$quarter->quarter - 1], $quarter->year);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function quarters(): array
    {
        $rows = [];
        $cursor = CarbonImmutable::now()->addQuarters(2)->startOfQuarter();

        for ($i = 0; $i < 6; $i++) {
            $rows[] = ['value' => $cursor->format('Y-m'), 'label' => $this->quarterLabel($cursor)];
            $cursor = $cursor->subQuarter();
        }

        return $rows;
    }
}
