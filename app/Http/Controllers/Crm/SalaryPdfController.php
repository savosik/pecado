<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use App\Services\Payroll\PayrollCalculationPresenter;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCatalog;
use App\Services\Payroll\PayrollScopeResolver;
use App\Services\Payroll\Support\MonthLabel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Зарплата на бумаге: расчётный лист и разбор с пояснениями.
 *
 * Лист — короткий документ «сколько начислено»: его распечатывают, подшивают,
 * показывают. Разбор — тот же расчёт, но со всеми числами формулы и словами,
 * почему вышло столько: он отвечает на вопрос «почему так мало» без звонка РОПу.
 *
 * Данные берутся из готового снимка и того же презентера, что кормит экран, —
 * бумага и страница не могут разойтись. Своя арифметика здесь не ведётся.
 */
class SalaryPdfController extends CrmController
{
    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollCalculationPresenter $presenter,
        private readonly PayrollCatalog $catalog,
    ) {}

    public function payslip(Request $request): Response
    {
        return $this->render($request, 'payroll.payslip', 'Расчётный лист');
    }

    public function explained(Request $request): Response
    {
        return $this->render($request, 'payroll.explained', 'Расчёт зарплаты с пояснениями');
    }

    private function render(Request $request, string $view, string $title): Response
    {
        $actor = $this->crmActor($request);
        $month = $this->month($request);
        $manager = $this->scopes->manager($actor, $request->integer('manager') ?: null);

        abort_if($manager === null || ! $manager->payroll_enabled, 404, 'Расчёт для этого сотрудника не ведётся.');

        $calculation = $this->calculations->ensureDraft((int) $manager->getKey(), $month);

        $pdf = Pdf::loadView($view, [
            'title' => $title,
            'manager' => $manager,
            'month' => $month,
            'monthLabel' => MonthLabel::ru($month),
            'calc' => $this->presenter->present($calculation),
            'explanations' => $this->catalog->explanations(),
            'generatedAt' => CarbonImmutable::now(),
        ])->setPaper('a4');

        return $pdf->download($this->filename($title, $manager, $month));
    }

    private function filename(string $title, PersonalManager $manager, CarbonImmutable $month): string
    {
        $name = str_replace(' ', '-', trim((string) $manager->name));

        return sprintf('%s-%s-%s.pdf', str_replace(' ', '-', $title), $name, $month->format('Y-m'));
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
}
