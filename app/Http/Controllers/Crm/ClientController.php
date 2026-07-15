<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends CrmController
{
    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesAllClients($request);

        $query = User::query()
            ->visibleInCrm($actor)
            ->with(['personalManager:id,name', 'clientStatus:id,name,color']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Фильтр по менеджеру — только тем, кто и так видит весь отдел.
        // Иначе менеджер подставил бы чужой manager_id в адрес и увидел чужих.
        $managerId = $seesAll ? $request->input('manager_id') : null;

        if ($managerId) {
            $query->where('personal_manager_id', $managerId);
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'email', 'created_at'];
        if (in_array($sortBy, $allowedSortFields, true)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        return Inertia::render('Crm/Pages/Clients/Index', [
            'clients' => $query->paginate($perPage)->withQueryString(),
            'managers' => $seesAll
                ? PersonalManager::query()->select('id', 'name')->orderBy('name')->get()
                : [],
            'canSeeAll' => $seesAll,
            'managerProfileLinked' => $seesAll || $actor->managerProfile !== null,
            'filters' => [
                'search' => $search,
                'manager_id' => $managerId,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function show(Request $request, int $client): Response
    {
        // Резолвим через тот же scope: чужой клиент — 404, а не 403.
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->with(['personalManager:id,name', 'clientStatus:id,name,color'])
            ->findOrFail($client);

        return Inertia::render('Crm/Pages/Clients/Show', [
            'client' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'city' => $user->city,
                'country' => $user->country,
                'status' => $user->status->value,
                'status_label' => $user->status_label,
                'manager' => $user->personalManager ? [
                    'id' => $user->personalManager->id,
                    'name' => $user->personalManager->name,
                ] : null,
                'client_status' => $user->clientStatus ? [
                    'name' => $user->clientStatus->name,
                    'color' => $user->clientStatus->color,
                ] : null,
                'created_at' => $user->created_at?->format('d.m.Y H:i'),
            ],
        ]);
    }
}
