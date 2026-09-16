<?php

namespace App\Http\Controllers\Crm;

use App\Services\Motivation\PartnerListService;
use App\Services\Motivation\PoolListService;
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
    /** Ключи адреса, которые списки понимают: фильтры, сортировка, страница, поиск. */
    private const QUERY_KEYS = ['filter', 'tab', 'history', 'sort', 'direction', 'page', 'search', 'drop', 'stopped', 'silent'];

    public function __construct(
        private readonly PayrollScopeResolver $scopes,
        private readonly PartnerListService $partners,
        private readonly PoolListService $pool,
        private readonly \App\Services\Motivation\PoolPackageService $packages,
        private readonly \App\Services\Payroll\PayrollParamsResolver $params,
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

    public function wake(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Wake', $this->payload($request, 'wake'));
    }

    public function wakeData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'wake'));
    }

    public function newPartners(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/NewPartners', $this->payload($request, 'newcomers'));
    }

    public function newPartnersData(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'newcomers'));
    }

    /**
     * «Выданные»: пакеты работника, правила обработки и кран.
     */
    public function packages(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $month = $this->month($request);
        $manager = $this->scopes->manager($actor, $request->integer('manager') ?: null);
        $defaults = (array) config('motivation.default_parameters', []);

        $payload = [
            'manager' => $manager === null ? null : ['id' => (int) $manager->getKey(), 'name' => (string) $manager->name],
            'scope_options' => $this->scopes->options($actor),
            'can_see_all' => $this->scopes->seesAll($actor),
            'rules' => null,
            'tap' => null,
            'packages' => [],
        ];

        if ($manager === null) {
            return Inertia::render('Crm/Pages/Motivation/Packages', $payload);
        }

        $managerId = (int) $manager->getKey();
        $rateP2 = (float) ($this->params->effective($managerId, $month)->for('motivation_variable')['rate_p2'] ?? $defaults['rate_p2'] ?? 0);

        $payload['rules'] = [
            'package_size' => (int) ($defaults['pool_package_size'] ?? 20),
            'contact_working_days' => (int) ($defaults['pool_contact_working_days'] ?? 10),
            'shipment_days' => (int) ($defaults['pool_shipment_days'] ?? 90),
            'tap_periods' => (int) ($defaults['pool_tap_periods'] ?? 2),
            'novelty_periods' => (int) ($defaults['novelty_periods'] ?? 6),
            'rate_p2_percent' => rtrim(rtrim(number_format($rateP2 * 100, 2, ',', ''), '0'), ','),
        ];
        $payload['tap'] = $this->pool->tap($managerId, $month);
        $payload['packages'] = $this->packages->forManager($managerId);

        return Inertia::render('Crm/Pages/Motivation/Packages', $payload);
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
            'query' => $request->only(self::QUERY_KEYS),
            'list' => null,
        ];

        if ($manager === null) {
            return $payload;
        }

        $managerId = (int) $manager->getKey();
        $query = $request->only(self::QUERY_KEYS);

        $payload['list'] = match ($list) {
            'rhythm' => $this->partners->rhythm($managerId, $month, $query),
            'wake' => $this->partners->wake($managerId, $month, $query),
            'newcomers' => $this->partners->newcomers($managerId, $month),
            default => $this->partners->base($managerId, $month, $query),
        };

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
