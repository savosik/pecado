<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use App\Services\Motivation\GuaranteeBaseService;
use App\Services\Motivation\ParallelCalculationService;
use App\Services\Motivation\ParameterOrderService;
use App\Services\Payroll\Exceptions\InvalidPayrollParams;
use App\Services\Payroll\PayrollCatalog;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Параметры мотивации» (mot-32): приказы с переменными Приложения № 1,
 * предпросмотр эффекта, история, отклонения по работнику.
 *
 * Просмотр — по crm-motivation.view: работник вправе проверить, по каким
 * ставкам его посчитали. Изменение — по crm-motivation.edit.
 */
class MotivationSettingsController extends CrmController
{
    public function __construct(
        private readonly ParameterOrderService $orders,
        private readonly PayrollCatalog $components,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Settings', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'values' => ['required', 'array'],
            'manager' => ['nullable', 'integer', 'min:1'],
        ], [
            'values.required' => 'Не переданы значения для предпросмотра.',
        ]);

        return response()->json($this->orders->preview(
            $this->month($request),
            (array) $data['values'],
            isset($data['manager']) ? (int) $data['manager'] : null,
        ));
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'values' => ['required', 'array'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'order_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:500'],
        ], [
            'effective_from.required' => 'Укажите период, с которого действует приказ.',
            'effective_from.regex' => 'Период — в формате ГГГГ-ММ.',
            'values.required' => 'Не переданы значения приказа.',
        ]);

        try {
            $result = $this->orders->issue(
                (array) $data['values'],
                CarbonImmutable::createFromFormat('Y-m-d', $data['effective_from'].'-01'),
                $data['order_number'] ?? null,
                isset($data['order_date']) ? CarbonImmutable::parse((string) $data['order_date']) : null,
                $data['comment'] ?? null,
                $this->crmActor($request),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'order_id' => (int) $result['order']->getKey(),
            'warnings' => $result['warnings'],
            'message' => sprintf('Приказ издан и действует с %s.', $result['order']->effective_from->format('m.Y')),
        ]);
    }

    public function storePersonal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:personal_managers,id'],
            'component_key' => ['required', 'string'],
            'params' => ['required', 'array'],
            'comment' => ['nullable', 'string', 'max:255'],
        ], [
            'manager_id.required' => 'Не указан работник.',
            'component_key.required' => 'Не указан компонент.',
            'params.required' => 'Не переданы параметры.',
        ]);

        try {
            $this->orders->savePersonal((int) $data['manager_id'], (string) $data['component_key'], (array) $data['params'], $this->crmActor($request), $data['comment'] ?? null);
        } catch (InvalidPayrollParams|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'personal' => $this->orders->personal()]);
    }

    /**
     * Зафиксировать базу гарантии переходного периода всем работникам (п. 12.3).
     */
    public function fixGuarantee(Request $request, GuaranteeBaseService $guarantee, ParallelCalculationService $parallel): JsonResponse
    {
        $data = $request->validate([
            'effective_from' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'overwrite' => ['nullable', 'boolean'],
        ], ['effective_from.regex' => 'Месяц введения задаётся в формате ГГГГ-ММ.']);

        $effective = ! empty($data['effective_from'])
            ? CarbonImmutable::parse($data['effective_from'].'-01')
            : ($parallel->window()['effective_from'] ?? null);

        if ($effective === null) {
            return response()->json(['message' => 'Схема 2.2 ещё не введена приказом — не от чего отсчитывать три предшествующих периода.'], 422);
        }

        $result = $guarantee->fix($effective, $this->crmActor($request), (bool) ($data['overwrite'] ?? false));
        $fixed = count(array_filter($result['rows'], fn (array $r): bool => ! $r['skipped']));

        return response()->json([
            'ok' => true,
            'message' => $fixed === 0 ? 'База уже зафиксирована у всех — ничего не изменено.' : sprintf('База гарантии зафиксирована: %d работников, действует до %s.', $fixed, $result['until']),
            'report' => $result,
            'personal' => $this->orders->personal(),
        ]);
    }

    public function resetPersonal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'exists:personal_managers,id'],
            'component_key' => ['required', 'string'],
        ]);

        $this->orders->resetPersonal((int) $data['manager_id'], (string) $data['component_key']);

        return response()->json(['ok' => true, 'personal' => $this->orders->personal()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $actor = $this->crmActor($request);
        $month = $this->month($request);

        $componentMeta = [];
        foreach (ParameterOrderService::ORDER_COMPONENTS as $key) {
            $component = $this->components->component($key);
            $componentMeta[$key] = [
                'label' => $component->label(),
                'schema' => $component->paramsSchema(),
                'defaults' => $component->defaults(),
            ];
        }

        return $this->orders->overview($month) + [
            'month_label' => MonthLabel::ru($month),
            'months' => $this->months(),
            'can_edit' => $actor->can('crm-motivation.edit'),
            'managers' => PersonalManager::query()->active()->where('payroll_enabled', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (PersonalManager $m): array => ['id' => (int) $m->getKey(), 'name' => (string) $m->name])->all(),
            'components' => $componentMeta,
        ];
    }

    private function month(Request $request): CarbonImmutable
    {
        $raw = (string) $request->input('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01');

            if ($month !== null) {
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
        $cursor = CarbonImmutable::now()->startOfMonth()->addMonths(3);

        for ($i = 0; $i < 15; $i++) {
            $rows[] = ['value' => $cursor->format('Y-m'), 'label' => MonthLabel::ru($cursor)];
            $cursor = $cursor->subMonth();
        }

        return $rows;
    }
}
