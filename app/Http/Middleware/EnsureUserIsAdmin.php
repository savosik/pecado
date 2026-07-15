<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Пользователь может зайти в админку, если у него есть хотя бы одно НЕ-CRM право.
     *
     * Сотрудник только с CRM-правами (например, РОП) работает в /crm — сюда его не пускаем.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect('/login')->with('error', 'Необходимо войти в систему');
        }

        if (! $request->user()->hasAdminAccess()) {
            return redirect('/')->with('error', 'Доступ запрещён. У вас нет прав для доступа к панели администрирования.');
        }

        return $next($request);
    }
}
