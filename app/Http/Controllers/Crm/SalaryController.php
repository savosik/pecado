<?php

namespace App\Http\Controllers\Crm;

use App\Services\Payroll\PayrollCalculationPresenter;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCatalog;
use App\Services\Payroll\PayrollInputCollector;
use App\Services\Payroll\PayrollScopeResolver;
use App\Services\Payroll\PayrollWhatIfService;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Моя зарплата»: сколько заработано на эту минуту и почему.
 *
 * Страница и polling-ответ собираются одним методом: цифры на экране и в
 * фоновом обновлении обязаны совпадать. Первый заход без снимка считает
 * синхронно; дальше черновик пересчитывают события и расписание.
 */
class SalaryController extends CrmController
{
    private const MONTHS_BACK = 12;

    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollCalculationPresenter $presenter,
        private readonly PayrollCatalog $catalog,
        private readonly PayrollInputCollector $collector,
        private readonly \App\Services\Payroll\PayrollCalculator $calculator,
        private readonly \App\Services\Payroll\PayrollParamsResolver $paramsResolver,
        private readonly PayrollWhatIfService $whatIf,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Salary/Index', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /**
     * Калькулятор «что если»: ползунки менеджера считаются тем же калькулятором.
     *
     * Формулы на фронте нет намеренно — иначе ползунок и настоящий расчёт
     * рано или поздно разойдутся. Страница присылает три числа, сервер
     * возвращает готовый разбор.
     */
    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager' => ['nullable', 'integer', 'min:1'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'revenue' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'active_clients' => ['required', 'integer', 'min:0'],
            'penalty' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ], [
            'revenue.required' => 'Не передана выручка.',
            'active_clients.required' => 'Не передано число активных клиентов.',
            'penalty.required' => 'Не передан штраф.',
        ]);

        $actor = $this->crmActor($request);
        $month = $this->month($request);
        $manager = $this->scopes->manager($actor, $request->integer('manager') ?: null);

        if ($manager === null || ! $manager->payroll_enabled) {
            return response()->json(['message' => 'Расчёт для этого менеджера не ведётся.'], 422);
        }

        $managerId = (int) $manager->getKey();
        $snapshot = $this->calculations->ensureDraft($managerId, $month);
        $inputs = \App\Services\Payroll\Dto\PayrollInputs::fromArray((array) $snapshot->inputs);
        $params = $this->paramsResolver->effective($managerId, $month);

        // Штраф задаётся суммой: подменяем накладные одной синтетической строкой
        // с той задержкой, которая даёт ровно этот штраф по действующей ступени.
        $penalty = (float) $data['penalty'];
        $tiers = (array) ($params->for('kpi_bonus')['discipline_penalty']['tiers'] ?? []);
        $coefficient = (float) ($tiers[0]['coefficient'] ?? 1.0);
        $days = (int) ($tiers[0]['from_days'] ?? 3);

        $simulated = $inputs->with([
            'revenue' => (float) $data['revenue'],
            'planned_clients' => $this->whatIf->withActive($inputs, (int) $data['active_clients']),
            'invoices' => $penalty <= 0.009 ? [] : [[
                'shipment_id' => 0,
                'erp_number' => null,
                'partner_id' => null,
                'partner_name' => '',
                'amount' => round($penalty / max(0.01, $coefficient), 2),
                'due_on' => null,
                'settled_on' => $inputs->month,
                'delay_working_days' => $days,
                'delay_calendar_days' => $days,
                'source' => 'simulation',
                'payment_status' => 'paid',
            ]],
        ]);

        $breakdown = $this->calculator->calculate($params, $simulated);
        $kpi = $breakdown->component('kpi_bonus');

        return response()->json([
            'total' => $breakdown->total,
            'components' => array_map(fn ($c): array => [
                'key' => $c->key,
                'label' => $c->label,
                'amount' => $c->amount,
            ], $breakdown->components),
            'performance' => $kpi?->meta['performance'] ?? null,
            'multiplier' => $kpi?->meta['multiplier'] ?? null,
            'capped' => (bool) ($kpi?->meta['capped'] ?? false),
        ]);
    }

    /**
     * Сводка по отделу — только тем, кто видит чужие деньги (crm-clients-all.view).
     */
    public function team(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Salary/Team', $this->teamPayload($request));
    }

    public function teamData(Request $request): JsonResponse
    {
        return response()->json($this->teamPayload($request));
    }

    /**
     * XLSX для бухгалтерии: строка на менеджера, колонки — компоненты дохода.
     */
    public function teamExport(Request $request, \App\Services\SimpleXlsxExporter $exporter): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $payload = $this->teamPayload($request);

        $rows = array_map(fn (array $row): array => [
            $row['manager']['name'],
            $row['calculation']['status_label'].($row['calculation']['version'] > 1 ? ' v'.$row['calculation']['version'] : ''),
            (float) ($row['amounts']['salary'] ?? 0),
            (float) ($row['amounts']['kpi_bonus'] ?? 0),
            $row['kpi']['penalty'],
            (float) ($row['amounts']['extra_income'] ?? 0),
            (float) ($row['amounts']['new_clients_bonus'] ?? 0),
            (float) ($row['amounts']['manual_correction'] ?? 0),
            $row['calculation']['total'],
            $row['inputs']['plan'],
            $row['inputs']['revenue'],
            $row['inputs']['percent'] === null ? null : round($row['inputs']['percent'] * 100, 1),
            $row['inputs']['active_count'].' из '.$row['inputs']['planned_count'],
            $row['kpi']['multiplier'],
            $row['calculation']['approved_at'] ? substr((string) $row['calculation']['approved_at'], 0, 10) : null,
            $row['calculation']['comment'],
        ], $payload['rows']);

        return $exporter->stream(
            sprintf('zarplata-%s.xlsx', $payload['month']),
            ['Менеджер', 'Статус', 'Оклад', 'KPI-премия', 'Штраф за дисциплину', 'Доп. доход', 'Новые клиенты', 'Корректировка', 'Итого', 'План', 'Реализации', 'Выполнение, %', 'Активные клиенты', 'Множитель', 'Утверждено', 'Комментарий'],
            $rows,
            'Зарплата '.$payload['month_label'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function teamPayload(Request $request): array
    {
        $month = $this->month($request);
        $rows = $this->calculations->teamSummary($month);

        $totals = ['total' => 0.0, 'salary' => 0.0, 'kpi_bonus' => 0.0, 'extra_income' => 0.0, 'new_clients_bonus' => 0.0, 'manual_correction' => 0.0, 'penalty' => 0.0, 'revenue' => 0.0, 'plan' => 0.0];
        $statuses = ['draft' => 0, 'approved' => 0, 'paid' => 0];

        foreach ($rows as $row) {
            $totals['total'] += $row['calculation']['total'];
            foreach (['salary', 'kpi_bonus', 'extra_income', 'new_clients_bonus', 'manual_correction'] as $key) {
                $totals[$key] += (float) ($row['amounts'][$key] ?? 0);
            }
            $totals['penalty'] += $row['kpi']['penalty'];
            $totals['revenue'] += $row['inputs']['revenue'];
            $totals['plan'] += (float) ($row['inputs']['plan'] ?? 0);
            $statuses[$row['calculation']['status']] = ($statuses[$row['calculation']['status']] ?? 0) + 1;
        }

        return [
            'month' => $month->format('Y-m'),
            'month_label' => MonthLabel::ru($month),
            'months' => $this->months(),
            'rows' => $rows,
            'totals' => array_map(fn (float $v): float => round($v, 2), $totals),
            'statuses' => $statuses,
            'can_edit' => $this->crmActor($request)->can('crm-salary.edit'),
            'poll_seconds' => max(15, (int) config('payroll.poll_seconds', 60)),
        ];
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
            'months' => $this->months(),
            'manager' => $manager === null ? null : ['id' => (int) $manager->getKey(), 'name' => (string) $manager->name],
            'participates' => $manager === null ? null : (bool) $manager->payroll_enabled,
            'scope_options' => $this->scopes->options($actor),
            'can_see_all' => $this->scopes->seesAll($actor),
            'can_edit' => $actor->can('crm-salary.edit'),
            'calculation' => null,
            'explanations' => $this->catalog->explanations(),
            'poll_seconds' => max(15, (int) config('payroll.poll_seconds', 60)),
            'server_time' => now()->toIso8601String(),
        ];

        // Исключённому из расчёта черновик не заводим: пустая страница с объяснением
        // честнее, чем нули, которые выглядят как «премия не начислена».
        if ($manager === null || ! $manager->payroll_enabled) {
            return $payload;
        }

        $calculation = $this->calculations->ensureDraft((int) $manager->getKey(), $month);
        $payload['calculation'] = $this->presenter->present($calculation);

        return $payload;
    }

    private function month(Request $request): CarbonImmutable
    {
        $raw = (string) $request->query('month', '');

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
