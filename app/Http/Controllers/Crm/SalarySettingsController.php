<?php

namespace App\Http\Controllers\Crm;

use App\Events\Payroll\PayrollInputsChanged;
use App\Http\Requests\Crm\StorePayrollParamsRequest;
use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Services\Payroll\Exceptions\InvalidPayrollParams;
use App\Services\Payroll\PayrollParamsResolver;
use App\Services\Payroll\PayrollSettingsService;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройки зарплаты для РОПа: константы на менеджера × месяц.
 *
 * Сохраняется полный набор параметров компонента; резолвер хранит только
 * разницу с нижним слоем и удаляет строку при совпадении. Замороженный месяц
 * не правится — сначала «переоткрыть» расчёт.
 */
class SalarySettingsController extends CrmController
{
    private const MONTHS_BACK = 6;

    private const MONTHS_FORWARD = 2;

    public function __construct(
        private readonly PayrollSettingsService $settings,
        private readonly PayrollParamsResolver $params,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Salary/Settings', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function storeParams(StorePayrollParamsRequest $request): JsonResponse
    {
        $managerId = (int) $request->integer('manager_id');
        $month = $this->monthOrNull($request->input('month'));

        if ($month !== null && $this->isFrozen($managerId, $month)) {
            return response()->json([
                'message' => 'Расчёт за этот месяц утверждён — сначала переоткройте его.',
            ], 422);
        }

        try {
            $this->params->save(
                $managerId,
                $month,
                (string) $request->input('component'),
                (array) $request->input('params', []),
                $this->crmActor($request),
                $request->input('comment') !== null ? (string) $request->input('comment') : null,
            );
        } catch (InvalidPayrollParams $e) {
            return response()->json([
                'message' => 'Параметры не прошли проверку.',
                'errors' => ['params' => $e->errors],
            ], 422);
        }

        PayrollInputsChanged::dispatch([$managerId], 'params', $month === null ? [] : [$month->toDateString()]);

        return response()->json([
            'saved' => true,
            'manager' => $this->settings->managerRow(PersonalManager::query()->findOrFail($managerId), $month ?? $this->month($request)),
        ]);
    }

    public function resetParams(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'integer', 'min:1'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'component' => ['required', 'string', 'max:40'],
        ], [
            'manager_id.required' => 'Не указан менеджер.',
            'month.regex' => 'Месяц должен быть в формате ГГГГ-ММ.',
            'component.required' => 'Не указан компонент.',
        ]);

        $managerId = (int) $data['manager_id'];
        $month = $this->monthOrNull($data['month'] ?? null);

        if ($month !== null && $this->isFrozen($managerId, $month)) {
            return response()->json(['message' => 'Расчёт за этот месяц утверждён — сначала переоткройте его.'], 422);
        }

        $this->params->reset($managerId, $month, (string) $data['component']);

        PayrollInputsChanged::dispatch([$managerId], 'params.reset', $month === null ? [] : [$month->toDateString()]);

        return response()->json([
            'saved' => true,
            'manager' => $this->settings->managerRow(PersonalManager::query()->findOrFail($managerId), $month ?? $this->month($request)),
        ]);
    }

    /**
     * Новая версия схемы отдела с месяца. Старые версии не правятся — утверждённые
     * месяцы должны перечитываться по той схеме, по которой считались.
     */
    public function storeScheme(\App\Http\Requests\Crm\StorePayrollSchemeRequest $request, \App\Services\Payroll\PayrollSchemeRepository $schemes, \App\Services\Payroll\PayrollParamsValidator $validator, \App\Services\Payroll\PayrollCatalog $catalog): JsonResponse
    {
        $components = [];
        $errors = [];

        foreach ((array) $request->input('components', []) as $index => $entry) {
            $key = (string) $entry['key'];
            $defaults = is_array($entry['defaults'] ?? null) ? $entry['defaults'] : [];
            $full = array_replace($catalog->component($key)->defaults(), $defaults);

            foreach ($validator->validate($key, $full) as $error) {
                $errors[] = $catalog->component($key)->label().': '.$error;
            }

            $components[] = ['key' => $key, 'enabled' => (bool) ($entry['enabled'] ?? true), 'defaults' => $full];
        }

        if ($errors !== []) {
            return response()->json(['message' => 'Умолчания схемы не прошли проверку.', 'errors' => ['components' => $errors]], 422);
        }

        $effectiveFrom = CarbonImmutable::createFromFormat('Y-m-d', $request->input('effective_from').'-01')->startOfMonth();
        $scheme = $schemes->createVersion(
            $components,
            $effectiveFrom,
            $this->crmActor($request),
            $request->input('comment') !== null ? (string) $request->input('comment') : null,
            $request->input('title') !== null ? (string) $request->input('title') : null,
        );

        $managerIds = PersonalManager::query()->active()->pluck('id')->map('intval')->all();
        if ($managerIds !== []) {
            PayrollInputsChanged::dispatch($managerIds, 'scheme', [$effectiveFrom->toDateString()]);
        }

        return response()->json([
            'saved' => true,
            'scheme' => ['id' => (int) $scheme->getKey(), 'version' => (int) $scheme->version, 'title' => (string) $scheme->title, 'effective_from' => $scheme->effective_from->toDateString()],
            'versions' => $this->settings->schemeVersions(),
        ]);
    }

    public function copyMonth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'to' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/', 'different:from'],
            'overwrite' => ['nullable', 'boolean'],
        ], [
            'from.required' => 'Не указан месяц-источник.',
            'to.required' => 'Не указан месяц-получатель.',
            'to.different' => 'Месяцы совпадают.',
            'from.regex' => 'Месяц должен быть в формате ГГГГ-ММ.',
            'to.regex' => 'Месяц должен быть в формате ГГГГ-ММ.',
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'].'-01')->startOfMonth();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'].'-01')->startOfMonth();

        $result = $this->params->copyMonth($from, $to, $this->crmActor($request), (bool) ($data['overwrite'] ?? false));

        $managerIds = PersonalManager::query()->active()->pluck('id')->map('intval')->all();
        if ($managerIds !== [] && $result['copied'] > 0) {
            PayrollInputsChanged::dispatch($managerIds, 'params.copy', [$to->toDateString()]);
        }

        return response()->json($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $month = $this->month($request);

        return array_merge($this->settings->overview($month), [
            'months' => $this->months(),
            'adjustments' => $this->settings->adjustments($month),
            'current_month' => CarbonImmutable::now()->format('Y-m'),
        ]);
    }

    private function month(Request $request): CarbonImmutable
    {
        return $this->monthOrNull($request->query('month')) ?? CarbonImmutable::now()->startOfMonth();
    }

    private function monthOrNull(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01')?->startOfMonth();
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

    /**
     * Прошлые месяцы — посмотреть и переоткрыть, будущие — заранее расставить константы.
     *
     * @return list<array{value: string, label: string}>
     */
    private function months(): array
    {
        $rows = [];
        $cursor = CarbonImmutable::now()->startOfMonth()->addMonths(self::MONTHS_FORWARD);

        for ($i = 0; $i < self::MONTHS_BACK + self::MONTHS_FORWARD + 1; $i++) {
            $rows[] = ['value' => $cursor->format('Y-m'), 'label' => MonthLabel::ru($cursor)];
            $cursor = $cursor->subMonth();
        }

        return $rows;
    }
}
