<?php

namespace App\Services\Notifications\Pulse;

use App\Models\NotificationDelivery;
use App\Models\NotificationSignal;
use App\Notifications\Pulse\PulseNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use Illuminate\Support\Facades\Notification;

/**
 * Постановка писем в очередь и запись решений в журнал.
 *
 * Каждый адресат получает отдельное письмо: список в одном `to` показал бы
 * получателям адреса друг друга. Так же устроен существующий листенер
 * уведомления менеджеров о заказе, и это правило сохраняется.
 */
class PulseDispatcher
{
    public function __construct(
        private readonly DeliveryGuard $guard,
        private readonly NotificationEventRegistry $registry,
        private readonly NotificationRenderer $renderer,
    ) {}

    /**
     * @param  array<int, ResolvedRecipient>  $recipients
     * @return array{queued: int, skipped: int}
     */
    public function dispatch(PulseSignal $signal, array $recipients, string $mode, bool $dryRun = false): array
    {
        $queued = 0;
        $skipped = 0;
        $limit = $this->guard->recipientLimit();
        $tooOld = $this->guard->isTooOld($signal);

        foreach (array_slice($recipients, 0, $limit) as $recipient) {
            $reason = match (true) {
                $dryRun => NotificationDelivery::REASON_DRY_RUN,
                $tooOld => NotificationDelivery::REASON_TOO_OLD,
                $mode !== PulseMode::MODE_LIVE => NotificationDelivery::REASON_SHADOW,
                default => $this->guard->reasonToSkip($recipient, $signal),
            };

            $delivery = $this->record($signal, $recipient, $reason);

            if ($delivery === null) {
                // Строка уже была: этот адрес получил письмо по другому правилу
                // в рамках того же сигнала. Уникальный индекс страхует от дубля
                // при повторном запуске job после сбоя очереди.
                $skipped++;

                continue;
            }

            if ($reason !== null) {
                $skipped++;

                continue;
            }

            $this->send($signal, $recipient, $delivery);
            $this->guard->registerSent();
            $queued++;
        }

        if (count($recipients) > $limit) {
            // Молчаливое усечение читалось бы как «охватили всех»
            $skipped += count($recipients) - $limit;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    private function record(PulseSignal $signal, ResolvedRecipient $recipient, ?string $reason): ?NotificationDelivery
    {
        $attributes = [
            'signal_uuid' => $signal->uuid,
            'event_key' => $signal->eventKey,
            'notification_rule_id' => $recipient->rule->id,
            'rule_name' => $recipient->rule->name,
            'client_user_id' => $signal->clientUserId,
            'company_id' => $signal->companyId,
            'contact_id' => $recipient->contactId,
            'channel' => $recipient->rule->channel,
            'recipient' => $recipient->key(),
            'recipient_kind' => $recipient->kind,
            'status' => $reason === null ? NotificationDelivery::STATUS_QUEUED : NotificationDelivery::STATUS_SKIPPED,
            'skip_reason' => $reason,
            'subject' => $this->renderer->subject($signal, $recipient->rule),
            'queued_at' => $reason === null ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // insertOrIgnore: дедупликация адресата гарантирована уникальным
        // индексом, а не только памятью процесса.
        $inserted = NotificationDelivery::query()->insertOrIgnore($attributes);

        if ($inserted === 0) {
            return null;
        }

        return NotificationDelivery::query()
            ->where('signal_uuid', $signal->uuid)
            ->where('channel', $attributes['channel'])
            ->where('recipient', $attributes['recipient'])
            ->first();
    }

    private function send(PulseSignal $signal, ResolvedRecipient $recipient, NotificationDelivery $delivery): void
    {
        Notification::route('mail', $recipient->email)->notify(new PulseNotification(
            signal: $signal,
            delivery: $delivery,
            subject: (string) $delivery->subject,
            template: $this->renderer->template($signal, $recipient->rule),
            unsubscribeUrl: $this->renderer->unsubscribeUrl($recipient),
        ));
    }

    /**
     * Записать сигнал целиком — включая случай «не совпало ни одно правило».
     *
     * Именно эта запись отвечает на вопрос «почему клиенту ничего не пришло»,
     * когда правил нет вовсе.
     *
     * @param  array<int, string>  $tags
     */
    public function recordSignal(
        PulseSignal $signal,
        array $tags,
        int $matchedRules,
        int $deliveries,
        string $mode,
        bool $dryRun,
    ): NotificationSignal {
        return NotificationSignal::create([
            'uuid' => $signal->uuid,
            'event_key' => $signal->eventKey,
            'client_user_id' => $signal->clientUserId,
            'company_id' => $signal->companyId,
            'subject_type' => $signal->subject !== null ? $signal->subject::class : null,
            'subject_id' => $signal->subject?->getKey(),
            'data' => $signal->data,
            'tags' => $tags,
            'view' => $signal->view,
            'matched_rules_count' => $matchedRules,
            'deliveries_count' => $deliveries,
            'dry_run' => $dryRun,
            'mode' => $mode,
            'occurred_at' => $signal->occurredAtOrNow(),
        ]);
    }
}
