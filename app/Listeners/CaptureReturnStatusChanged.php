<?php

namespace App\Listeners;

use App\Events\ReturnStatusChanged;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;

/**
 * Статус заявки на возврат изменился → уведомление «Статус возврата».
 *
 * Адресатов выбирает матрица (`system.return_status_changed`); прямой
 * отправки клиенту здесь нет с note-10.
 */
class CaptureReturnStatusChanged
{
    private const OCCASION = 'system.return_status_changed';

    public function handle(ReturnStatusChanged $event): void
    {
        $return = $event->productReturn;
        $number = $return->erp_number ?: $return->number ?: (string) $return->id;

        app(MailStream::class)->captureQuietly(new Occasion(
            key: self::OCCASION,
            clientUserId: $return->user_id,
            subject: $return,
            data: [
                'return_number' => $number,
                'order_number' => $number,
                'status' => is_object($return->status) ? $return->status->value : $return->status,
                'previous_status' => is_object($event->previousStatus)
                    ? $event->previousStatus->value
                    : $event->previousStatus,
            ],
            view: [
                'title' => sprintf('Возврат %s: изменился статус', $number),
                'body' => 'Статус заявки на возврат изменился. Подробности — в личном кабинете.',
                'entity_label' => "Возврат {$number}",
            ],
        ));
    }
}
