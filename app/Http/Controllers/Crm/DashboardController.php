<?php

namespace App\Http\Controllers\Crm;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends CrmController
{
    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesAllClients($request);

        // Тот же scope, что и в списке клиентов, — цифры не разъедутся с выдачей.
        $visibleClients = User::query()->visibleInCrm($actor)->count();

        return Inertia::render('Crm/Pages/Dashboard', [
            'stats' => [
                'visible_clients' => $visibleClients,
                'department_clients' => $seesAll
                    ? User::query()->whereNotNull('personal_manager_id')->count()
                    : null,
                'managers' => $seesAll
                    ? \App\Models\PersonalManager::query()->count()
                    : null,
            ],
            'seesAll' => $seesAll,
            'managerProfileLinked' => $seesAll || $actor->managerProfile !== null,
        ]);
    }
}
