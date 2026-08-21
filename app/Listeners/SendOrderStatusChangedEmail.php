<?php

namespace App\Listeners;

use App\Events\OrderUpdated;
use App\Models\Order;
use App\Notifications\Orders\OrderStatusChangedNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\PulseMode;
use App\Support\Notifications\SignalBus;

class SendOrderStatusChangedEmail
{
    /** Ключ события пульта, которым это письмо заменяется при переходе. */
    private const PULSE_EVENT = 'orders.status_changed';

    public function handle(OrderUpdated $event): void
    {
        $order = $event->order;

        if (! $order->wasChanged('status')) {
            return;
        }

        // Сигнал пульту идёт всегда: в теневом режиме он только считает
        // получателей для сверки, а отправляет по-прежнему код ниже.
        $this->signalPulse($order);

        // Событие переведено на пульт — здесь молчим, иначе клиент получил бы
        // два письма об одном и том же. Флаг общий для обеих сторон, поэтому
        // дубль невозможен по конструкции, а не по внимательности.
        if (PulseMode::handles(self::PULSE_EVENT)) {
            return;
        }

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
    private function signalPulse(Order $order): void
    {
        $number = $order->erp_number ?: $order->number;

        app(SignalBus::class)->publish(new PulseSignal(
            eventKey: self::PULSE_EVENT,
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
