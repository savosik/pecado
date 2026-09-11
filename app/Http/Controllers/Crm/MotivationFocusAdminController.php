<?php

namespace App\Http\Controllers\Crm;

use App\Models\Motivation\MotivationFocusRule;
use App\Services\Motivation\FocusRuleService;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Фокус-перечень — экран руководителя (mot-36; форма B7).
 *
 * Правила включения, состав на период, добавление с проверкой дат по п. 6.4.3,
 * блок отдачи. Утверждённые периоды читаются по снимку и правкой не меняются.
 */
class MotivationFocusAdminController extends CrmController
{
    public function __construct(private readonly FocusRuleService $rules) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/FocusAdmin', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'in:brand,category,product'],
            'q' => ['nullable', 'string', 'max:100'],
        ], ['scope.in' => 'Неизвестный вид правила.']);

        return response()->json(['options' => $this->rules->search((string) $data['scope'], (string) ($data['q'] ?? ''))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'in:brand,category,product'],
            'target_id' => ['required', 'integer', 'min:1'],
            'rate' => ['nullable', 'numeric'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'order_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [
            'scope.in' => 'Неизвестный вид правила.',
            'target_id.required' => 'Выберите бренд, категорию или позицию.',
            'starts_on.required' => 'Укажите дату включения.',
            'starts_on.date' => 'Дата включения указана неверно.',
        ]);

        try {
            $rule = $this->rules->create($data, $this->crmActor($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => sprintf('Правило № %d добавлено в перечень.', $rule->getKey())] + $this->payload($request));
    }

    public function close(Request $request, MotivationFocusRule $rule): JsonResponse
    {
        $data = $request->validate(['ends_on' => ['required', 'date']], ['ends_on.required' => 'Укажите дату исключения.']);

        try {
            $this->rules->close($rule, CarbonImmutable::parse((string) $data['ends_on']));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Правило закрыто: позиции выходят из перечня с '.CarbonImmutable::parse((string) $data['ends_on'])->addDay()->format('d.m.Y').'.'] + $this->payload($request));
    }

    public function freeze(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $count = $this->rules->freeze($month);

        return response()->json([
            'ok' => true,
            'message' => $count === 0 ? 'Снимок за этот период уже есть.' : sprintf('Состав за %s заморожен: %d позиций.', MonthLabel::ru($month), $count),
        ] + $this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $month = $this->month($request);

        return $this->rules->overview($month) + [
            'month_label' => MonthLabel::ru($month),
            'can_edit' => $this->crmActor($request)->can('crm-motivation.edit'),
        ];
    }

    private function month(Request $request): CarbonImmutable
    {
        $raw = (string) $request->query('month', '');

        return preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $raw) === 1
            ? CarbonImmutable::parse(substr($raw, 0, 7).'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }
}
