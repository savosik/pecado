<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\ClientInsightService;
use App\Services\Crm\ClientListService;
use App\Services\Crm\ClientPlanFactService;
use App\Services\Crm\ClientProfileService;
use App\Support\Crm\ClientListFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Клиенты: список, карточка, сводка по продажам.
 *
 * Отбор и сборка строки — тот же `ClientListService`, что и в веб-списке, вместе
 * с тем же `ClientListFilters`: белый список фильтров лежит в одном месте, и
 * агент не может передать в отбор ничего, чего не может передать человек.
 */
class ClientOperations
{
    use ResolvesCrmEntities;

    public function __construct(
        private readonly ClientListService $clients,
        private readonly ClientProfileService $profiles,
        private readonly ClientPlanFactService $planFact,
        private readonly ClientInsightService $insights,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $filters = ClientListFilters::fromRequest(
            new Request($input->all()),
            $actor,
            $actor->can('crm-clients-all.view'),
        );

        $page = $this->clients->paginate($actor, $filters);

        return [
            'data' => $page->items(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'filters' => $filters->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, OperationInput $input): array
    {
        $client = User::query()
            ->visibleInCrm($actor)
            ->with(['personalManager:id,name', 'clientStatus:id,name,color'])
            ->findOrFail((int) $input->int('client'));

        $month = CarbonImmutable::now()->startOfMonth();
        $planFact = $this->planFact->forClients([(int) $client->getKey()], $month);
        $row = $planFact[(int) $client->getKey()] ?? ['plan' => null, 'fact' => 0.0];

        $card = [
            'id' => (int) $client->getKey(),
            'name' => $client->display_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'manager' => $client->personalManager?->name,
            'status' => $client->clientStatus?->name,
            'registered_at' => $client->created_at?->toDateString(),
            'month' => [
                'period' => $month->format('Y-m'),
                'plan' => $row['plan'] === null ? null : (float) $row['plan'],
                'fact' => (float) $row['fact'],
            ],
        ];

        // Профиль — только с правом на него: «вижу клиента» и «вижу, что
        // менеджер о нём записал» — разные уровни доступа.
        if ($actor->can('crm-profile.view')) {
            $profile = $this->profiles->forClient($client);

            $card['profile'] = [
                'lifecycle_status' => $profile->lifecycle_status->value,
                'payment_behavior' => $profile->payment_behavior?->value,
                'preferred_channel' => $profile->preferred_channel?->value,
                'sentiment' => $profile->sentiment?->value,
                'order_cycle_days' => $profile->order_cycle_days,
                'notes_md' => $profile->notes_md,
                'interests' => $this->profiles->interests($client),
            ];
        }

        return $card;
    }

    /**
     * Сводка по продажам клиента за период: деньги, помесячная динамика,
     * разрезы по брендам и категориям.
     *
     * Считает тот же `ShipmentAnalyticsService`, что и отчёт продаж, с контекстом
     * из одного клиента — второго движка расчёта выручки в проекте нет.
     *
     * @return array<string, mixed>
     */
    public function sales(User $actor, OperationInput $input): array
    {
        return $this->insights->forClient(
            $this->client($actor, $input),
            $actor,
            (int) ($input->int('months') ?? 12),
        );
    }
}
