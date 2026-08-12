<?php

namespace App\Enums\Crm;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Разрез рабочего экрана CRM: свои записи или записи всего отдела.
 *
 * Отвечает на вопрос «на чём я сейчас сфокусирован», а не «что мне разрешено».
 * Разрешает право `crm-department.view`; этот enum лишь сужает уже разрешённое.
 * Поэтому пускать его в show-роуты нельзя — там граница проходит по праву,
 * иначе получилась бы безопасность через состояние интерфейса.
 *
 * Дефолт — {@see self::MINE}: отсутствие параметра в запросе означает «мои»,
 * а не «весь отдел». Иначе curl, сохранённая закладка, выгрузка и ИИ-агент
 * молча получали бы отдел целиком, хотя никто об этом не просил.
 */
enum CrmScope: string
{
    case MINE = 'mine';
    case DEPARTMENT = 'department';

    /**
     * Разрез из запроса с оглядкой на права и на состав отдела.
     *
     * `department` без права молча схлопывается в `mine` — тот же приём, что
     * с фильтром по менеджеру: значение вне разрешённого не проверяется,
     * а гасится.
     */
    public static function fromRequest(Request $request, User $actor): self
    {
        return self::resolve($request->input('scope'), $actor);
    }

    /**
     * Разрез из произвольного значения (запрос, сохранённый отбор, вызов API).
     */
    public static function resolve(mixed $value, User $actor): self
    {
        if (! $actor->can('crm-department.view')) {
            return self::MINE;
        }

        // У сотрудника без карточки менеджера «мои» — пустой экран: за ним
        // не закреплён ни один партнёр. Показывать вместо отдела пустоту
        // бессмысленно, поэтому для него разрез всегда «отдел».
        if ($actor->managerProfile?->id === null) {
            return self::DEPARTMENT;
        }

        return self::tryFrom((string) $value) ?? self::MINE;
    }

    public function isMine(): bool
    {
        return $this === self::MINE;
    }

    public function label(): string
    {
        return match ($this) {
            self::MINE => 'Только мои',
            self::DEPARTMENT => 'Весь отдел',
        };
    }
}
