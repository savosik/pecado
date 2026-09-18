<?php

namespace App\Http\Middleware;

use App\Models\WmsAccessLink;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Телефон, вошедший по ссылке кладовщика, выходит сам, когда ссылку перевыпустили или отключили (pick-17).
 *
 * Сессии живут в Redis, удалить чужую по пользователю нечем — поэтому версия доступа хранится и в ссылке,
 * и в сессии, а расхождение закрывает сессию на первом запросе. Обычных пользователей не касается.
 */
class EnsureWmsLinkSessionValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $linkId = $request->session()->get(WmsAccessLink::SESSION_LINK);
        if ($linkId === null) {
            return $next($request);
        }

        $link = WmsAccessLink::query()->find($linkId);
        $valid = $link !== null && $link->isActive()
            && (int) $request->session()->get(WmsAccessLink::SESSION_VERSION) === (int) $link->session_version
            && Auth::id() === $link->user_id;

        if ($valid) {
            // «Киоск»: у вошедшего по ссылке нет ничего, кроме выдачи заказов, — остальные адреса
            // кабинета склада уводят обратно на экран выдачи.
            $route = (string) ($request->route()?->getName() ?? '');
            if (! str_starts_with($route, 'wms.pickups.')) {
                return redirect()->route('wms.pickups.index');
            }

            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('error', 'Ссылка для входа на склад перевыпущена или отключена. Попросите у начальника склада новую.');
    }
}
