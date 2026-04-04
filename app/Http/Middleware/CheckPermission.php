<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Проверяет, имеет ли текущий пользователь указанное право.
     *
     * Использование в роутах:
     *   ->middleware('permission:products.view')
     *   ->middleware('permission:products.create')
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Необходима авторизация.');
        }

        // Super-admin имеет все права (через Gate::before)
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        if (!$user->hasPermissionTo($permission)) {
            abort(403, 'Недостаточно прав для выполнения этого действия.');
        }

        return $next($request);
    }
}
