<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Http\Controllers\Crm\ImpersonationController;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->status === UserStatus::BLOCKED) {
            // Клиента заблокировали, пока менеджер смотрел сайт его глазами:
            // возвращаем менеджера в CRM. Иначе чужая блокировка выкинула бы
            // его на /login вместе с рабочей сессией.
            if (Impersonation::active()) {
                return app(ImpersonationController::class)->stop($request);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Ваш аккаунт заблокирован. Если вы считаете это ошибкой, пожалуйста, свяжитесь с нами.',
            ]);
        }

        return $next($request);
    }
}
