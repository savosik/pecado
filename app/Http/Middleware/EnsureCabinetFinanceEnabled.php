<?php

namespace App\Http\Middleware;

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
 */
class EnsureCabinetFinanceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('cabinet.finance_enabled'), 404);

        return $next($request);
    }
}
