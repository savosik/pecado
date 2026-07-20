<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

abstract class WmsController extends Controller
{
    /**
     * Текущий сотрудник склада. Гарантированно не null — маршруты закрыты 'auth'.
     */
    protected function wmsActor(Request $request): User
    {
        return $request->user();
    }

    /**
     * Начальник склада — видит сводку по всему складскому хозяйству.
     *
     * Пока разграничения по складам нет (все сотрудники видят все склады),
     * признак используется только для подписей на дашборде.
     */
    protected function isWarehouseHead(Request $request): bool
    {
        return $this->wmsActor($request)->hasRole(['warehouse-head', 'super-admin']);
    }
}
