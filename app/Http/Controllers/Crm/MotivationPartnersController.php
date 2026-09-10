<?php

namespace App\Http\Controllers\Crm;

use App\Services\Motivation\PartnerListService;
use App\Services\Payroll\PayrollScopeResolver;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Списки партнёров работника: «Моя база» и «Кто выпал из ритма» (mot-27).
 *
 * Страница и JSON-ответ собираются одним методом; фильтры, сортировка
 * и страница живут в адресе — состояние должно переживать перезагрузку
 * и открываться по ссылке из «Моего месяца».
 */
class MotivationPartnersController extends CrmController
{
    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PartnerListService $partners,
    ) {}

    public function base(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Base', $this->payload($request, 'base'));
    }

    public function baseData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'base'));
    }

    public function rhythm(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Rhythm', $this->payload($request, 'rhythm'));
    }

    public function rhythmData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'rhythm'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $list): array
    {
        $actor = $this->crmActor($request);
        $month = $this->month($request);
        $manager = $this->scopes->manager($actor, $request->integer('manager') ?: null);

        $payload = [
            'month' => $month->format('Y-m'),
            'month_label' => MonthLabel::ru($month),
            'manager' => $manager === null ? null : ['id' => (int) $manager->getKey(), 'name' => (string) $manager->name],
            'scope_options' => $this->scopes->options($actor),
            'can_see_all' => $this->scopes->seesAll($actor),
            'query' => $request->only(['filter', 'sort', 'direction', 'page', 'search', 'drop', 'stopped', 'silent']),
            'list' => null,
        ];

        if ($manager === null) {
            return $payload;
        }

        $query = $request->only(['filter', 'sort', 'direction', 'page', 'search', 'drop', 'stopped', 'silent']);
        $payload['list'] = $list === 'rhythm'
            ? $this->partners->rhythm((int) $manager->getKey(), $month, $query)
            : $this->partners->base((int) $manager->getKey(), $month, $query);

        return $payload;
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
}
