<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\User;
use App\Services\Client\Api\Usage\UsageReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «ИИ-агенты клиентов»: пользуются ли партнёры MCP-сервером и API v1, кто,
 * как часто и для чего.
 *
 * Экран оценки инструмента, а не управления им: токены выдаёт сам клиент в
 * кабинете, здесь только наблюдение. Разрез «мои / отдел» — общий для CRM.
 */
class AgentUsageController extends CrmController
{
    public function __construct(private readonly UsageReport $report) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $scope = CrmScope::fromRequest($request, $actor);
        $days = UsageReport::period($request->input('period'));
        $since = UsageReport::since($days);

        $clients = $this->report->visibleClients($actor, $scope);

        return Inertia::render('Crm/Pages/AgentUsage/Index', [
            'summary' => $this->report->summary($clients, $since),
            'daily' => $this->report->daily($clients, $since, $days),
            'partners' => $this->report->partners($clients, $since),
            'operations' => $this->report->operations($clients, $since),
            'agents' => $this->report->agents($clients, $since),
            'errors' => $this->report->errors($clients, $since),
            'idleTokens' => $this->report->idleTokens($clients, $since),
            'filters' => [
                'period' => $days,
                'scope' => $scope->value,
            ],
            'periods' => UsageReport::PERIODS,
            'canSeeDepartment' => $this->seesDepartment($request),
            'endpoints' => [
                'mcp' => url('/mcp/client'),
                'rest' => url('/api/client/v1/me'),
                'docs' => url('/docs/client-api'),
            ],
        ]);
    }

    public function show(Request $request, int $client): Response
    {
        $actor = $this->crmActor($request);
        $days = UsageReport::period($request->input('period'));
        $since = UsageReport::since($days);

        // Граница — по праву, а не по разрезу экрана: чужой партнёр даёт 404,
        // не подтверждая существования (как карточка партнёра).
        $scope = $this->seesDepartment($request) ? CrmScope::DEPARTMENT : CrmScope::MINE;
        $partner = $this->report->visibleClients($actor, $scope)
            ->with('personalManager:id,name')
            ->findOrFail($client);

        $own = User::query()->whereKey($partner->getKey());

        return Inertia::render('Crm/Pages/AgentUsage/Show', [
            'partner' => [
                'id' => (int) $partner->getKey(),
                'name' => (string) $partner->display_name,
                'manager' => $partner->personalManager?->name,
                'url' => route('crm.clients.show', $partner->getKey()),
            ],
            'summary' => $this->report->summary($own, $since),
            'daily' => $this->report->daily($own, $since, $days),
            'operations' => $this->report->operations($own, $since),
            'agents' => $this->report->agents($own, $since),
            'errors' => $this->report->errors($own, $since),
            'calls' => $this->report->partnerCalls($partner, $since),
            'filters' => ['period' => $days],
            'periods' => UsageReport::PERIODS,
        ]);
    }
}
