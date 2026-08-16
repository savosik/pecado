<?php

namespace App\Services\Crm;

use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Резолв «действующего менеджера»: кто фактически ведёт клиентов на дату.
 *
 * Единая точка для кабинета клиента (контакты в дашборде) и роутинга писем
 * о заказах: обе поверхности обязаны показывать одного и того же человека,
 * иначе клиент пишет одному, а письма получает другой.
 *
 * Ровно один переход: цепочки «А замещает Б, Б замещает В» запрещены валидацией
 * при создании отсутствия, поэтому рекурсия не нужна. Если данные всё же
 * противоречивы (правились руками), возвращается прямой замещающий — предсказуемость
 * важнее. Кеша нет намеренно: это point-lookup по составному индексу в таблице
 * на десятки строк, а кеш дал бы лаг «отпуск завели, клиент ещё видит отпускника».
 */
class ManagerAbsenceResolver
{
    /**
     * Активное отсутствие менеджера на дату (по умолчанию — сегодня), с загруженным замещающим.
     */
    public function activeAbsence(PersonalManager $manager, ?CarbonInterface $on = null): ?ManagerAbsence
    {
        return $manager->absences()
            ->activeOn($on ?? Carbon::today())
            ->with('substitute')
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Карточка, фактически ведущая клиентов менеджера на дату: замещающий при
     * активном отсутствии с заполненным substitute_manager_id, иначе сам менеджер.
     */
    public function effectiveManager(PersonalManager $manager, ?CarbonInterface $on = null): PersonalManager
    {
        return $this->activeAbsence($manager, $on)?->substitute ?? $manager;
    }

    /**
     * Полный контекст для UI: кто действует, кого замещает и до какой даты.
     */
    public function resolve(PersonalManager $manager, ?CarbonInterface $on = null): ManagerResolution
    {
        $absence = $this->activeAbsence($manager, $on);

        if ($absence === null || $absence->substitute === null) {
            return new ManagerResolution($manager);
        }

        return new ManagerResolution(
            manager: $absence->substitute,
            absentManager: $manager,
            until: Carbon::instance($absence->ends_on),
        );
    }
}
