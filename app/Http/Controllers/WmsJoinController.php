<?php

namespace App\Http\Controllers;

use App\Models\WmsAccessLink;
use App\Services\Wms\AccessLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Вход кладовщика по ссылке (pick-17): открыл ссылку из мессенджера — и в кабинете склада.
 *
 * Без пароля, «запомнить» на 90 дней. Недействующая ссылка ведёт на обычный вход с понятной
 * подсказкой: попросить у начальника склада новую.
 */
class WmsJoinController extends Controller
{
    public function __invoke(Request $request, string $token, AccessLinkService $links): RedirectResponse
    {
        $link = $links->findActiveByToken($token);

        if ($link === null || $link->user === null) {
            return redirect('/login')->with('error', 'Ссылка для входа на склад не действует. Попросите у начальника склада новую.');
        }

        // Чужая сессия на этом телефоне (например, менеджер) закрывается: ссылка — это другой человек.
        if (Auth::check() && Auth::id() !== $link->user_id) {
            Auth::logout();
            $request->session()->invalidate();
        }

        // Учётка ссылки видит только экран выдачи: роль приводим к актуальной при каждом входе
        // (ссылки, выпущенные до появления роли, получали «кладовщика»).
        $link->user->syncRoles([AccessLinkService::ROLE]);

        Auth::login($link->user, remember: true);
        $request->session()->regenerate();
        $request->session()->put(WmsAccessLink::SESSION_LINK, $link->id);
        $request->session()->put(WmsAccessLink::SESSION_VERSION, $link->session_version);

        $link->forceFill(['uses_count' => $link->uses_count + 1, 'last_used_at' => now()])->save();

        return redirect()->route('wms.pickups.index')->with('success', 'Вы вошли как «'.$link->user->name.'». Добавьте эту страницу на главный экран телефона — так она открывается одним нажатием.');
    }
}
