<?php

namespace App\Support\Cabinet;

use App\Models\User;
use App\Support\Debt\DebtControl;

/**
 * Доступность платёжки «бери и плати» (pay-01).
 *
 * Платёжка открыта там же, где клиент видит долг: при включённом разделе
 * «Оплаты» или боевой лестнице долга — иначе показывать суммы нечего.
 * Предикат вынесен из контроллера, чтобы кабинет и клиентский API решали
 * одним и тем же условием.
 */
final class PaymentOrdersGate
{
    public static function availableFor(?User $user): bool
    {
        return CabinetFinance::enabledFor($user) || DebtControl::live(DebtControl::ACTION_CABINET);
    }
}
