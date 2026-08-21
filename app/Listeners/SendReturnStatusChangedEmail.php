<?php

namespace App\Listeners;

use App\Events\ReturnStatusChanged;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Notifications\Returns\ReturnStatusChangedNotification;
use App\Services\Notifications\Pulse\NotificationPulse;
use App\Services\Notifications\Pulse\PulseMode;

class SendReturnStatusChangedEmail
{
    /** Ключ события пульта, которым это письмо заменяется при переходе. */
    private const PULSE_EVENT = 'system.return_status_changed';

    public function handle(ReturnStatusChanged $event): void
    {
        $this->signalPulse($event);

        // Событие переведено на пульт — здесь молчим. Один флаг на обе стороны,
        // поэтому двойного письма быть не может.
        if (PulseMode::handles(self::PULSE_EVENT)) {
            return;
        }

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

    private function signalPulse(ReturnStatusChanged $event): void
    {
        $return = $event->productReturn;
        $number = $return->erp_number ?: $return->number ?: (string) $return->id;

        app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: self::PULSE_EVENT,
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
