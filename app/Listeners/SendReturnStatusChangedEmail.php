<?php

namespace App\Listeners;

use App\Events\ReturnStatusChanged;
use App\Notifications\Returns\ReturnStatusChangedNotification;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;

class SendReturnStatusChangedEmail
{
    /** Ключ события пульта, которым это письмо заменяется при переходе. */
    private const OCCASION = 'system.return_status_changed';

    public function handle(ReturnStatusChanged $event): void
    {
        $this->composeLetter($event);

        if (! config('notifications.mail.features.return_status_changes')) {
            return;
        }

        $user = $event->productReturn->user;

        if (blank($user->email)) {
            return;
        }

        $user->notify(new ReturnStatusChangedNotification(
            $event->productReturn,
            $event->previousStatus,
        ));
    }

    private function composeLetter(ReturnStatusChanged $event): void
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
