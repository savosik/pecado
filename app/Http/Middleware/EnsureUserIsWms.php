<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsWms
{
    /**
     * Пользователь может зайти в кабинет склада, если у него есть хотя бы одно WMS-право.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect('/login')->with('error', 'Необходимо войти в систему');
        }

        if (! $request->user()->hasWmsAccess()) {
            return redirect('/')->with('error', 'Доступ запрещён. У вас нет прав для доступа к кабинету склада.');
        }

        return $next($request);
    }
}
