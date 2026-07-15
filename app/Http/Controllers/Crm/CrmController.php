<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

abstract class CrmController extends Controller
{
    /**
     * Текущий сотрудник CRM. Гарантированно не null — маршруты закрыты 'auth'.
     */
    protected function crmActor(Request $request): User
    {
        return $request->user();
    }

    /**
     * Видит ли сотрудник клиентов всего отдела (РОП), а не только своих.
     *
     * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
     */
    protected function seesAllClients(Request $request): bool
    {
        return $this->crmActor($request)->can('crm-clients-all.view');
    }
}
