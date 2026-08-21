<?php

namespace App\Listeners;

use App\Events\ReturnCreated;
use App\Models\ProductReturn;
use App\Notifications\Returns\ReturnCreatedNotification;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;

class SendReturnCreatedEmail
{
    /** Ключ события пульта, которым это письмо заменяется при переходе. */
    private const OCCASION = 'system.return_created';

    public function handle(ReturnCreated $event): void
    {
        $this->composeLetter($event->productReturn);

        if (! config('notifications.mail.features.return_created')) {
            return;
        }

        $user = $event->productReturn->user;

        if (blank($user->email)) {
            return;
        }

        $user->notify(new ReturnCreatedNotification($event->productReturn));
    }

    private function composeLetter(ProductReturn $return): void
    {
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
