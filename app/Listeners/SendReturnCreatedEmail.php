<?php

namespace App\Listeners;

use App\Events\ReturnCreated;
use App\Models\ProductReturn;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Notifications\Returns\ReturnCreatedNotification;
use App\Services\Notifications\Pulse\PulseMode;
use App\Support\Notifications\SignalBus;

class SendReturnCreatedEmail
{
    /** Ключ события пульта, которым это письмо заменяется при переходе. */
    private const PULSE_EVENT = 'system.return_created';

    public function handle(ReturnCreated $event): void
    {
        $this->signalPulse($event->productReturn);

        // Событие переведено на пульт — здесь молчим, иначе клиент получил бы
        // два письма. Один флаг на обе стороны.
        if (PulseMode::handles(self::PULSE_EVENT)) {
            return;
        }

        if (! config('notifications.mail.features.return_created')) {
            return;
        }

        $user = $event->productReturn->user;

        if (blank($user->email)) {
            return;
        }

        $user->notify(new ReturnCreatedNotification($event->productReturn));
    }

    private function signalPulse(ProductReturn $return): void
    {
        $number = $return->erp_number ?: $return->number ?: (string) $return->id;

        app(SignalBus::class)->publish(new PulseSignal(
            eventKey: self::PULSE_EVENT,
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
