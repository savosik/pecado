<?php

namespace App\Support\Notifications;

use App\Models\Order;
use App\Models\User;

class OrderManagerRouting
{
    /**
     * Список email-получателей менеджерского уведомления о заказе.
     *
     * Собирается из двух источников:
     *  1) Все пользователи с ролью `sales-manager` (Spatie Permission), у которых заполнен email.
     *  2) Персональный менеджер клиента (`$order->user->personalManager->email`), если у клиента
     *     назначен `personal_manager_id` и у менеджера есть email.
     *
     * Дубликаты убираются.
     *
     * @return array<int, string>
     */
    public static function recipients(Order $order): array
    {
        $emails = User::role('sales-manager')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')
            ->all();

        $personalEmail = $order->user?->personalManager?->email;

        if (filled($personalEmail)) {
            $emails[] = $personalEmail;
        }

        return array_values(array_unique($emails));
    }
}
