<?php

namespace App\Support\Notifications;

use App\Models\Order;
use App\Services\Crm\ManagerAbsenceResolver;

class OrderManagerRouting
{
    /**
     * Список email-получателей менеджерского уведомления о заказе.
     *
     * Получатель ровно один — **персональный менеджер клиента**
     * (`users.personal_manager_id` → `personal_managers.email`). Заказ это
     * работа с конкретным клиентом, а не новость для отдела: рассылка «всем,
     * у кого есть роль» показывала состав и суммы чужих заказов тем, кто этих
     * клиентов не ведёт, и тонула в почте у остальных.
     *
     * На время отсутствия менеджера с назначенным замещающим (abs-01,
     * `ManagerAbsenceResolver`) письмо уходит замещающему — тому же человеку,
     * чьи контакты в этот период видит клиент в кабинете. Если у замещающего
     * в карточке пустой email, письмо возвращается на адрес самого менеджера:
     * он прочитает после выхода, что лучше потери письма.
     *
     * Если менеджер не назначен или email в итоге пуст, письмо уходит
     * на резервный адрес из `notifications.mail.order_fallback_recipients` —
     * иначе заказ «ничьего» клиента не увидит никто. Пустой список = не слать.
     *
     * @return array<int, string>
     */
    public static function recipients(Order $order): array
    {
        $card = $order->user?->personalManager;

        $email = $card
            ? (app(ManagerAbsenceResolver::class)->effectiveManager($card)->email ?: $card->email)
            : null;

        if (filled($email)) {
            return [$email];
        }

        return array_values(array_unique(
            config('notifications.mail.order_fallback_recipients', [])
        ));
    }
}
