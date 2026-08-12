<?php

namespace App\Http\Controllers\Crm;

use App\Models\User;
use App\Services\Crm\BedsService;
use App\Services\Crm\ClientInsightService;
use App\Services\Crm\OpportunityService;
use App\Services\Crm\PlanScope;
use App\Services\Crm\PlanScopeResolver;
use App\Services\Crm\SalesPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Грядки» — план периода одной картинкой.
 *
 * Скоуп резолвится тем же {@see PlanScopeResolver}, что у планов и возможностей:
 * три экрана про один и тот же отдел обязаны показывать одну и ту же базу,
 * и разными резолверами это свойство держится ровно до первой правки одного из них.
 */
class BedsController extends CrmController
{
    public function __construct(
        private readonly BedsService $beds,
        private readonly PlanScopeResolver $scopes,
        private readonly SalesPlanService $plans,
    ) {}

    /**
     * Страница раздела. GET /crm/beds
     */
    public function index(Request $request): Response
    {
        $month = $this->plans->parseMonth($request->string('month')->value());
        $actor = $this->crmActor($request);

        return Inertia::render('Crm/Pages/Beds/Index', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->plans->monthLabel($month),
            'scopeOptions' => $this->scopes->options($actor),
            'canSeeAll' => $this->seesDepartment($request),
        ]);
    }

    /**
     * Плитки полотна. GET /crm/beds/data
     *
     * Руководитель по умолчанию смотрит отдел плитками менеджеров: «где просело»
     * на уровне отдела — вопрос про людей, а не про восемьсот партнёров сразу.
     * Выбрав менеджера, он попадает в те же грядки партнёров, что видит менеджер.
     */
    public function data(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $month = $this->plans->parseMonth($request->string('month')->value());
        $scope = $this->resolveScope($request);

        $wantsManagers = $this->seesDepartment($request)
            && $request->string('scope')->value() !== 'manager'
            && $request->string('view')->value() !== 'clients';

        $canvas = $wantsManagers
            ? $this->beds->managers($month, $scope, $actor)
            : $this->beds->clients($month, $scope, $actor);

        return response()->json($canvas + [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->plans->monthLabel($month),
            'scope' => $this->scopes->payload($scope),
            'scopeOptions' => $this->scopes->options($actor),
            'canSeeAll' => $this->seesDepartment($request),
        ]);
    }

    /**
     * Провал в партнёра. GET /crm/beds/{client}/details
     *
     * Класс ABC и признак «спит» берутся из сигналов возможностей, а не считаются
     * заново: одна и та же буква на плитке и в карточке — это требование, а не
     * совпадение.
     */
    public function details(
        Request $request,
        int $client,
        ClientInsightService $insights,
        OpportunityService $opportunities,
    ): JsonResponse {
        $actor = $this->crmActor($request);

        // Тот же скоуп, что и в списке: чужой партнёр — 404, а не 403.
        $model = User::query()->visibleInCrm($actor)->findOrFail($client);

        $month = $this->plans->parseMonth($request->string('month')->value());
        $signals = $opportunities->signals($month, $this->resolveScope($request));

        return response()->json($insights->forClient($model, $actor, 12, true) + [
            'signals' => $signals[$client] ?? null,
        ]);
    }

    private function resolveScope(Request $request): PlanScope
    {
        return $this->scopes->resolve(
            $this->crmActor($request),
            $request->string('scope')->value() ?: null,
            (int) $request->input('scope_id', 0) ?: null,
        );
    }
}
