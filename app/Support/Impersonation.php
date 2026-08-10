<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * Режим «просмотр сайта от имени клиента» (CRM → кабинет клиента).
 *
 * Единственное место, знающее про ключ сессии: его читают
 *  - App\Http\Middleware\RestrictImpersonatedActions (запрет документов),
 *  - App\Http\Middleware\HandleInertiaRequests (плашка и подавление диалога пароля),
 *  - App\Http\Controllers\Auth\AuthController (кнопка «Выйти» в шапке сайта),
 *  - App\Http\Controllers\User\SearchController (не писать историю поиска клиенту).
 *
 * Хранится только id менеджера: имя и права берутся из БД при выходе — за время
 * просмотра сотрудника могли заблокировать или лишить доступа в CRM.
 */
class Impersonation
{
    /**
     * Ключ сессии с id менеджера, который смотрит сайт от имени клиента.
     */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Идёт ли просмотр от имени клиента прямо сейчас.
     */
    public static function active(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    /**
     * Id менеджера, начавшего просмотр, или null — если режим не активен.
     */
    public static function managerId(): ?int
    {
        $id = Session::get(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    /**
     * Пометить сессию как «менеджер смотрит от имени клиента».
     *
     * Вызывается ПОСЛЕ Auth::login($client): login() мигрирует сессию
     * (данные переезжают, но полагаться на порядок незачем).
     */
    public static function start(User $manager): void
    {
        Session::put(self::SESSION_KEY, $manager->id);
    }

    /**
     * Снять пометку и вернуть id менеджера (null — если режима не было).
     */
    public static function stop(): ?int
    {
        $id = self::managerId();

        Session::forget(self::SESSION_KEY);

        return $id;
    }
}
