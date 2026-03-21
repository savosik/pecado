<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-01 v4: Принудительная смена пароля.
 *
 * Пользователи, созданные при выгрузке из 1С (partner.created),
 * получают временный пароль и обязаны сменить его при первом входе.
 */
class EnsurePasswordChanged
{
    /**
     * Маршруты, которые разрешены без смены пароля.
     */
    private const ALLOWED_ROUTES = [
        'cabinet.password.change',
        'cabinet.password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // Пропускаем разрешённые маршруты
            $currentRoute = $request->route()?->getName();
            if ($currentRoute && in_array($currentRoute, self::ALLOWED_ROUTES, true)) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Необходимо сменить пароль.',
                    'redirect' => route('cabinet.password.change'),
                ], 403);
            }

            return redirect()->route('cabinet.password.change')
                ->with('warning', 'Необходимо сменить пароль для продолжения работы.');
        }

        return $next($request);
    }
}
