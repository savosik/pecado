<?php

namespace App\Listeners;

use App\Events\OrderUpdated;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;

/**
 * Смена статуса заказа → уведомление «Смена статуса заказа».
 *
 * Листенер только сообщает матрице, что случилось. Кому писать и о каких
 * статусах — настройка партнёра (`orders.status_changed`, подтип по статусу);
 * прямой отправки клиенту здесь нет с note-10.
 */
class CaptureOrderStatusChanged
{
    private const OCCASION = 'orders.status_changed';

    public function handle(OrderUpdated $event): void
    {
        $order = $event->order;

        if (! $order->wasChanged('status')) {
            return;
        }

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
