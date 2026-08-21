<?php

namespace App\Http\Middleware;

use App\Support\Cabinet\CabinetFinance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Закрывает раздел «Оплаты» в личном кабинете, пока цифры долга не сверены с 1С.
 *
 * 404, а не 403: отключённого раздела для клиента просто не существует, и
 * «доступ запрещён» породил бы вопрос менеджеру, которого можно не создавать.
 *
 * Маршруты при этом остаются зарегистрированными — иначе `route('cabinet.payments.*')`
 * в шаблонах и редиректах падал бы с RouteNotFoundException вместо аккуратной 404.
 *
 * debt-01: до глобального включения раздел открыт пилотной группе сверенных
 * клиентов — см. CabinetFinance::enabledFor().
 */
class EnsureCabinetFinanceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(CabinetFinance::enabledFor($request->user()), 404);

        return $next($request);
    }
}
