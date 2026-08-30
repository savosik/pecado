<?php

namespace App\Http\Controllers\Crm;

use App\Services\Payroll\PayrollCalculationPresenter;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollCatalog;
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
            'explanations' => $this->catalog->explanations(),
            'poll_seconds' => max(15, (int) config('payroll.poll_seconds', 60)),
            'server_time' => now()->toIso8601String(),
        ];

        if ($manager === null) {
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
