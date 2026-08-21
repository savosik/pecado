<?php

namespace App\Support\Notifications;

use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Crm\Mail\MailStream;
use App\Services\Notifications\Pulse\NotificationPulse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Единственная точка, куда доменный код сообщает «произошло вот это».
 *
 * Доменный код не должен знать, кто и как это использует. Сегодня повод уходит
 * в поток писем (новая модель) и в пульт уведомлений (старая, работающая
 * вхолостую до полного перехода). Когда пульт демонтируют, изменится только
 * этот класс, а не полтора десятка мест, где случаются события.
 */
class SignalBus
{
    public function __construct(
        private readonly MailStream $stream,
        private readonly NotificationPulse $pulse,
    ) {}

    public function publish(PulseSignal $signal, bool $dryRun = false): void
    {
        if (! $dryRun) {
            $this->captureSafely($signal);
        }

        $this->pulse->signal($signal, $dryRun);
    }

    /**
     * Сборка письма не имеет права уронить то, что его породило.
     *
     * Заказ приезжает из 1С в транзакции, и исключение при составлении письма
     * откатило бы сам заказ. Данные важнее уведомления о них.
     */
    private function captureSafely(PulseSignal $signal): void
    {
        try {
            $this->stream->capture($signal);
        } catch (Throwable $exception) {
            Log::error('Поток писем: не удалось собрать письмо', [
                'event' => $signal->eventKey,
                'client_user_id' => $signal->clientUserId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
