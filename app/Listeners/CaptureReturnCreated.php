<?php

namespace App\Listeners;

use App\Events\ReturnCreated;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;

/**
 * Заявка на возврат создана → уведомление «Заявка на возврат принята».
 *
 * Адресатов выбирает матрица (`system.return_created`); прямой отправки
 * клиенту здесь нет с note-10.
 */
class CaptureReturnCreated
{
    private const OCCASION = 'system.return_created';

    public function handle(ReturnCreated $event): void
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
                'items_count' => (int) $return->items()->count(),
                'total' => (float) ($return->total ?? 0),
            ],
            view: [
                'title' => sprintf('Возврат %s принят', $number),
                'body' => 'Заявка на возврат зарегистрирована. Мы сообщим, когда она будет обработана.',
                'entity_label' => "Возврат {$number}",
            ],
        ));
    }
}
