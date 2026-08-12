<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отметка «последний визит на сайт» — то, по чему CRM понимает, живой ли кабинет.
 *
 * Пишем активность, а не момент входа: с «запомнить меня» партнёр логинится раз
 * в полгода, и по логину выходило бы «не заходил с марта» у того, кто открывает
 * сайт каждый день.
 *
 * Частота обновления задаётся `crm.presence.track_throttle_seconds`. Карточке
 * партнёра хватало и пятнадцати минут, но полоске «кто сейчас на сайте» такой
 * шаг превращает «сейчас» в «в течение последней четверти часа» — то есть
 * в ту же колонку последнего визита. Плата за точность — во столько же раз
 * более частый UPDATE по users.
 *
 * Просмотр от имени партнёра (impersonation) отметку не двигает: иначе менеджер
 * своим же заходом «оживлял» бы карточку, и признак «ни разу не заходил» —
 * ровно то, ради чего всё затевалось, — врал бы именно там, где его смотрят.
 */
class TrackUserLastSeen
{
    /**
     * Насколько отметка «залипает», прежде чем её обновят снова.
     *
     * Живёт в конфиге, а не константой: от неё зависит и точность полоски
     * присутствия в CRM, и частота UPDATE по users. Менять это значение —
     * решение про нагрузку, а не про код.
     */
    private function throttleSeconds(): int
    {
        return (int) config('crm.presence.track_throttle_seconds', 60);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->touch($request);

        return $next($request);
    }

    private function touch(Request $request): void
    {
        $user = $request->user();

        if ($user === null || Impersonation::active()) {
            return;
        }

        $key = 'user-last-seen:'.$user->getKey();

        // add() — атомарная «постановка замка»: параллельные запросы одного
        // пользователя (страница + её XHR) не дадут пачку одинаковых UPDATE.
        if (! Cache::add($key, true, $this->throttleSeconds())) {
            return;
        }

        // Точечный UPDATE вместо save(): модель могла быть изменена другим кодом
        // в этом же запросе, и save() записал бы заодно чужие правки.
        DB::table('users')
            ->where('id', $user->getKey())
            ->update(['last_seen_at' => now()]);
    }
}
