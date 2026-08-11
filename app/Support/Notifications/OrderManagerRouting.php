<?php

namespace App\Support\Notifications;

use App\Models\Order;

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
     * Если менеджер не назначен или у его карточки пустой email, письмо уходит
     * на резервный адрес из `notifications.mail.order_fallback_recipients` —
     * иначе заказ «ничьего» клиента не увидит никто. Пустой список = не слать.
     *
     * @return array<int, string>
     */
    public static function recipients(Order $order): array
    {
        $personalEmail = $order->user?->personalManager?->email;

        if (filled($personalEmail)) {
            return [$personalEmail];
        }

        return array_values(array_unique(
            config('notifications.mail.order_fallback_recipients', [])
        ));
    }
}
