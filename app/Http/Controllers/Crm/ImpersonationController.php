<?php

namespace App\Http\Controllers\Crm;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Просмотр сайта от имени клиента.
 *
 * Менеджер переключает свою сессию на клиента, ходит по витрине и кабинету
 * его глазами и возвращается обратно в карточку. Что в этом режиме запрещено —
 * решает App\Http\Middleware\RestrictImpersonatedActions, а не скрытые кнопки:
 * гарантия «заказ за клиента не оформить» должна быть серверной.
 */
class ImpersonationController extends CrmController
{
    public function start(Request $request, int $client): RedirectResponse
    {
        $manager = $this->crmActor($request);

        // Вложенный вход: сессия хранит одного менеджера, второй вход потерял бы
        // дорогу назад. Практически недостижимо (в режиме нет доступа в /crm),
        // но состояние сессии важнее удобства проверки.
        if (Impersonation::active()) {
            return back()->with('error', 'Просмотр от имени клиента уже идёт.');
        }

        // Резолвим через тот же скоуп, что и карточка: чужой клиент — 404,
        // а не 403. Скоуп заодно отсекает сотрудников и служебные аккаунты.
        $target = User::query()
            ->visibleInCrm($manager)
            ->findOrFail($client);

        // Заблокированного не пускаем осознанно: EnsureUserIsNotBlocked
        // разлогинил бы сессию на первом же запросе, и менеджер вылетел бы из CRM.
        if ($target->status === UserStatus::BLOCKED) {
            return back()->with('error', 'Клиент заблокирован — войти под ним нельзя.');
        }

        Auth::login($target);

        // Строго после login(): он мигрирует сессию.
        Impersonation::start($manager);

        Log::info('crm.impersonation.start', [
            'manager_id' => $manager->id,
            'client_id' => $target->id,
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('cabinet.dashboard')
            ->with('info', "Вы смотрите сайт от имени клиента «{$target->display_name}».");
    }

    /**
     * Завершить просмотр и вернуть менеджера в его сессию.
     *
     * Маршрут живёт в routes/web.php под 'auth', а не в CRM-группе: в момент
     * нажатия сессия принадлежит клиенту, и EnsureUserIsCrm его не пустил бы.
     */
    public function stop(Request $request): RedirectResponse
    {
        abort_unless(Impersonation::active(), 403);

        $clientId = $request->user()?->id;
        $managerId = Impersonation::stop();

        $manager = $managerId === null ? null : User::find($managerId);

        // Пока менеджер смотрел, его могли заблокировать, удалить или лишить
        // доступа в CRM — возвращать в такую учётку нельзя.
        if ($manager === null
            || $manager->status === UserStatus::BLOCKED
            || ! $manager->hasCrmAccess()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Сессия менеджера недоступна, войдите заново.');
        }

        Auth::login($manager);

        Log::info('crm.impersonation.stop', [
            'manager_id' => $manager->id,
            'client_id' => $clientId,
            'ip' => $request->ip(),
        ]);

        if ($clientId === null) {
            return redirect()->route('crm.clients.index');
        }

        return redirect()->route('crm.clients.show', $clientId);
    }
}
