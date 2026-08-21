<?php

namespace App\Listeners;

use App\Events\OrderUpdated;
use App\Models\Order;
use App\Notifications\Orders\OrderStatusChangedNotification;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;

class SendOrderStatusChangedEmail
{
    /** Ключ события пульта, которым это письмо заменяется при переходе. */
    private const OCCASION = 'orders.status_changed';

    public function handle(OrderUpdated $event): void
    {
        $order = $event->order;

        if (! $order->wasChanged('status')) {
            return;
        }

        // Письмо в поток собирается всегда: кому оно уйдёт, решают
        // правила-фильтры, а клиенту отправляет по-прежнему код ниже.
        $this->composeLetter($order);

        if (! config('notifications.mail.features.order_status_changes')) {
            return;
        }

        // Клиенту шлём только при физических переходах из 1С (отгрузили, оплатили, закрыли).
        // Ручные правки админа клиенту не уходят — менеджер сам объясняет.
        if (! $order->fromErp) {
            return;
        }

        $whitelist = (array) config('notifications.mail.order_statuses_to_notify_client', []);

        if (! in_array($order->status->value, $whitelist, true)) {
            return;
        }

        $user = $order->user;

        if (! $user || blank($user->email)) {
            return;
        }

        $user->notify(new OrderStatusChangedNotification($order, $order->previousStatus));
    }

    /**
     * Сообщить пульту о смене статуса.
     *
     * Условие «только физические переходы из 1С» и список статусов остаются
     * здесь как поведение старого листенера, но пульту передаются данными:
     * там это условие системного правила, которое можно менять в интерфейсе.
     */
    private function composeLetter(Order $order): void
    {
        $number = $order->erp_number ?: $order->number;

        app(MailStream::class)->captureQuietly(new Occasion(
            key: self::OCCASION,
            clientUserId: $order->user_id,
            companyId: $order->company_id,
            subject: $order,
            data: [
                'order_number' => $number,
                'status' => $order->status?->value,
                'status_label' => $order->status?->label(),
                'previous_status' => $order->previousStatus?->value,
                'order_type' => $order->type?->value,
                'total' => (float) ($order->total ?? 0),
                'from_erp' => (bool) $order->fromErp,
            ],
            view: [
                'title' => sprintf('Заказ %s: %s', $number, $order->status?->label()),
                'body' => sprintf('Статус заказа %s изменился на «%s».', $number, $order->status?->label()),
                'url' => url(route('cabinet.orders.show', $order, false)),
                'entity_label' => "Заказ {$number}",
            ],
        ));
    }
}
