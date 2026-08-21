<?php

namespace App\Support\Cabinet;

use App\Models\User;

/**
 * Доступ клиента к денежным данным кабинета (раздел «Оплаты», балансы, графики).
 *
 * Глобальный флаг CABINET_FINANCE_ENABLED открывает финансы всем клиентам.
 * До него действует пилотная лесенка (карточка debt-01): раздел видит только
 * белый список CABINET_FINANCE_PILOT_USERS — клиенты, чьи балансы сверены
 * с 1С командой `debt:verify-balances` и глазами. Список временный: после
 * включения глобального флага его нужно вычистить из .env.
 */
final class CabinetFinance
{
    public static function enabledFor(?User $user): bool
    {
        if ((bool) config('cabinet.finance_enabled')) {
            return true;
        }

        return $user !== null && in_array((int) $user->getKey(), self::pilotUserIds(), true);
    }

    /**
     * @return list<int>
     */
    public static function pilotUserIds(): array
    {
        $raw = trim((string) config('cabinet.finance_pilot_user_ids', ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $raw),
        )));
    }
}
