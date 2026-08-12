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
     * Видит ли сотрудник записи всего отдела, а не только свои.
     *
     * Это про охват данных: партнёры коллег, их задачи, звонки, письма
     * и документы. Отвечает за взаимозаменяемость менеджеров.
     *
     * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
     */
    protected function seesDepartment(Request $request): bool
    {
        return $this->crmActor($request)->can('crm-department.view');
    }

    /**
     * Доступны ли сотруднику разрезы по менеджерам: чужая выручка, план отдела,
     * планы других менеджеров, фильтр по менеджерам.
     *
     * Намеренно отделено от {@see seesDepartment()}. «Вижу карточку партнёра
     * коллеги» и «вижу, сколько коллега продал» — разные полномочия: первое
     * нужно для подмены коллеги, второе остаётся у руководителя. Пока они были
     * одним правом, выдать первое означало выдать и право переписать план отдела.
     */
    protected function seesManagerBreakdown(Request $request): bool
    {
        return $this->crmActor($request)->can('crm-clients-all.view');
    }
}
