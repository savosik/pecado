<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use App\Services\Motivation\PayslipService;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollScopeResolver;
use App\Services\Payroll\Support\MonthLabel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Расчётный лист (mot-31): экран, PDF, версии, возражение.
 *
 * Бумага и страница строятся одним сервисом из одного снимка — разойтись
 * они не могут. Своей арифметики нет.
 */
class MotivationPayslipController extends CrmController
{
    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PayrollCalculationService $calculations,
        private readonly PayslipService $payslips,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Payslip', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function pdf(Request $request): HttpResponse
    {
        $actor = $this->crmActor($request);
        $month = $this->month($request);
        $manager = $this->scopes->manager($actor, $request->integer('manager') ?: null);

        abort_if($manager === null || ! $manager->payroll_enabled, 404, 'Расчёт для этого работника не ведётся.');

        $calculation = $this->payslips->version((int) $manager->getKey(), $month, $request->integer('version') ?: null)
            ?? $this->calculations->ensureDraft((int) $manager->getKey(), $month);

        $pdf = Pdf::loadView('payroll.motivation-payslip', [
            'title' => 'Расчётный лист',
            'manager' => $manager,
            'month' => $month,
            'monthLabel' => MonthLabel::ru($month),
            'slip' => $slip = $this->payslips->build($calculation),
            // Общий заголовок PDF (payroll._header) читает статус и версию из `calc`.
            'calc' => $slip['calculation'],
            'generatedAt' => CarbonImmutable::now(),
        ])->setPaper('a4');

        return $pdf->download($this->filename($manager, $month, (int) $calculation->version));
    }

    /**
     * Возражение по утверждённому расчёту (п. 11.3).
     */
    public function objection(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'calculation' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'reason.required' => 'Опишите причину возражения.',
            'reason.min' => 'Опишите причину возражения — хотя бы одним предложением.',
            'reason.max' => 'Причина слишком длинная: до 2000 знаков.',
        ]);

        $actor = $this->crmActor($request);
        $calculation = \App\Models\PayrollCalculation::query()->findOrFail((int) $data['calculation']);
        $manager = $this->scopes->manager($actor, (int) $calculation->personal_manager_id);

        // Возражать можно только по своему расчёту; руководитель — по любому.
        abort_if($manager === null || (int) $manager->getKey() !== (int) $calculation->personal_manager_id, 403, 'Возражение подаётся по собственному расчёту.');

        try {
            $this->payslips->object($calculation, $actor, (string) $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['reason' => $e->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', 'Возражение подано. Руководитель ответит на него или переоткроет расчёт.');
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
            'is_own' => $manager !== null && (int) ($actor->managerProfile?->getKey() ?? 0) === (int) $manager->getKey(),
            'requested_version' => $request->integer('version') ?: null,
            'slip' => null,
        ];

        if ($manager === null || ! $manager->payroll_enabled) {
            return $payload;
        }

        $calculation = $this->payslips->version((int) $manager->getKey(), $month, $request->integer('version') ?: null)
            ?? $this->calculations->ensureDraft((int) $manager->getKey(), $month);

        $payload['slip'] = $this->payslips->build($calculation);

        return $payload;
    }

    private function filename(PersonalManager $manager, CarbonImmutable $month, int $version): string
    {
        $name = str_replace(' ', '-', trim((string) $manager->name));

        return sprintf('Расчётный-лист-%s-%s-v%d.pdf', $name, $month->format('Y-m'), $version);
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

    /**
     * @return list<array{value: string, label: string}>
     */
    private function months(): array
    {
        $rows = [];
        $cursor = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['value' => $cursor->format('Y-m'), 'label' => MonthLabel::ru($cursor)];
            $cursor = $cursor->subMonth();
        }

        return $rows;
    }
}
