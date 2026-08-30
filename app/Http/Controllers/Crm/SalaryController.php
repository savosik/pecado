<?php

namespace App\Http\Controllers\Crm;

use App\Services\Payroll\PayrollCalculationPresenter;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCatalog;
use App\Services\Payroll\PayrollInputCollector;
use App\Services\Payroll\PayrollScopeResolver;
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
            'scope_options' => $this->scopes->options($actor),
            'can_see_all' => $this->scopes->seesAll($actor),
            'can_edit' => $actor->can('crm-salary.edit'),
            'calculation' => null,
            'timeline' => null,
            'explanations' => $this->catalog->explanations(),
            'poll_seconds' => max(15, (int) config('payroll.poll_seconds', 60)),
            'server_time' => now()->toIso8601String(),
        ];

        if ($manager === null) {
            return $payload;
        }

        $calculation = $this->calculations->ensureDraft((int) $manager->getKey(), $month);
        $payload['calculation'] = $this->presenter->present($calculation);
        $payload['timeline'] = $this->collector->shipmentsTimeline((int) $manager->getKey(), $month);

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
