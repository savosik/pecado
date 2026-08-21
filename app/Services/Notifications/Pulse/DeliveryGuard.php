<?php

namespace App\Services\Notifications\Pulse;

use App\Models\ClientContact;
use App\Models\NotificationDelivery;
use App\Models\NotificationSuppression;
use App\Notifications\Pulse\Support\PulseSignal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Проверки перед отправкой.
 *
 * Каждый отказ — не молчание, а строка в журнале с конкретной причиной:
 * менеджер должен видеть, что письмо не ушло, и почему именно. Молчаливый
 * пропуск здесь означал бы возврат к состоянию «выясняется через жалобу клиента».
 */
class DeliveryGuard
{
    /** Счётчик отправок за минуту — общий потолок движка. */
    private int $sentThisRun = 0;

    /**
     * Причина, по которой адресату отказано, или null — можно отправлять.
     */
    public function reasonToSkip(ResolvedRecipient $recipient, PulseSignal $signal): ?string
    {
        if (! $this->isValidEmail($recipient->email)) {
            return NotificationDelivery::REASON_INVALID_EMAIL;
        }

        if (NotificationSuppression::blocks($recipient->email, $signal->eventKey)) {
            return NotificationDelivery::REASON_SUPPRESSED;
        }

        if ($this->contactUnsubscribed($recipient)) {
            return NotificationDelivery::REASON_UNSUBSCRIBED;
        }

        if ($this->needsConsent($signal) && ! $this->hasConsent($recipient)) {
            return NotificationDelivery::REASON_NO_CONSENT;
        }

        if ($this->isThrottled($recipient)) {
            return NotificationDelivery::REASON_THROTTLED;
        }

        if ($this->exceedsRateLimit()) {
            return NotificationDelivery::REASON_RATE_LIMITED;
        }

        return null;
    }

    /**
     * Отложить ли отправку: правило со сведением копит письма, тихие часы
     * переносят отправку на конец окна.
     *
     * Отличается от отказа: письмо не теряется, а уходит позже. Поэтому
     * доставка остаётся в статусе «в очереди», а не «пропущено».
     */
    public function shouldDefer(ResolvedRecipient $recipient): bool
    {
        $rule = $recipient->rule;

        if (($rule->digest ?? 'none') !== 'none') {
            return true;
        }

        return $this->inQuietHours($rule->quiet_hours);
    }

    /**
     * Ночное окно, когда письма не тревожат.
     *
     * Часовой пояс — проекта, а не получателя: пояса контакта в системе нет,
     * и заводить его ради этого не стоит.
     *
     * @param  array<string, mixed>|null  $window
     */
    private function inQuietHours(?array $window): bool
    {
        $from = $window['from'] ?? null;
        $to = $window['to'] ?? null;

        if (blank($from) || blank($to)) {
            return false;
        }

        $now = now()->format('H:i');

        // Окно через полночь (22:00–08:00) сравнивается иначе, чем дневное
        return $from <= $to
            ? ($now >= $from && $now < $to)
            : ($now >= $from || $now < $to);
    }

    /**
     * Сигнал слишком стар, чтобы о нём писать.
     *
     * Главный предохранитель домена: первичная выгрузка истории из 1С или
     * пересчёт балансов физически не могут разослать письма.
     */
    public function isTooOld(PulseSignal $signal): bool
    {
        $minutes = (int) config('notification_pulse.limits.max_signal_age_minutes', 120);

        if ($minutes <= 0) {
            return false;
        }

        return $signal->occurredAtOrNow()->lt(now()->subMinutes($minutes));
    }

    public function registerSent(): void
    {
        $this->sentThisRun++;
    }

    /**
     * Потолок адресатов на один сигнал — страховка от правила,
     * раскрывшегося в сотни адресов.
     */
    public function recipientLimit(): int
    {
        return (int) config('notification_pulse.limits.max_recipients_per_signal', 20);
    }

    private function isValidEmail(string $email): bool
    {
        return ! Validator::make(['email' => $email], ['email' => 'required|email:rfc'])->fails();
    }

    private function contactUnsubscribed(ResolvedRecipient $recipient): bool
    {
        if ($recipient->contactId === null) {
            return false;
        }

        return ClientContact::query()
            ->whereKey($recipient->contactId)
            ->whereNotNull('unsubscribed_at')
            ->exists();
    }

    /**
     * Рекламные события требуют согласия, транзакционные — нет.
     *
     * Граница проходит по домену, а не по усмотрению менеджера: «акция»
     * и «ваш заказ отгружен» не должны зависеть от одной галочки.
     */
    private function needsConsent(PulseSignal $signal): bool
    {
        return explode('.', $signal->eventKey)[0] === 'campaigns';
    }

    private function hasConsent(ResolvedRecipient $recipient): bool
    {
        if ($recipient->contactId === null) {
            return false;
        }

        return ClientContact::query()
            ->whereKey($recipient->contactId)
            ->where('marketing_consent', true)
            ->exists();
    }

    /**
     * Не чаще, чем задано правилом, на один адрес.
     *
     * Мотив прикладной: 1С правит заказ построчно, и без ограничения клиент
     * получит десяток писем об одном изменении.
     */
    private function isThrottled(ResolvedRecipient $recipient): bool
    {
        $seconds = (int) ($recipient->rule->throttle_seconds ?? 0);

        if ($seconds <= 0) {
            return false;
        }

        return NotificationDelivery::query()
            ->where('notification_rule_id', $recipient->rule->id)
            ->where('recipient', $recipient->key())
            ->where('status', '!=', NotificationDelivery::STATUS_SKIPPED)
            ->where('created_at', '>=', now()->subSeconds($seconds))
            ->exists();
    }

    private function exceedsRateLimit(): bool
    {
        $limit = (int) config('notification_pulse.limits.max_deliveries_per_minute', 120);

        if ($limit <= 0) {
            return false;
        }

        $recent = NotificationDelivery::query()
            ->whereIn('status', [NotificationDelivery::STATUS_QUEUED, NotificationDelivery::STATUS_SENT])
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recent < $limit) {
            return false;
        }

        // Превышение потолка — событие, о котором нужно знать: обычно это
        // означает либо ошибку в правиле, либо неожиданный поток из 1С.
        Log::warning('Пульт уведомлений: превышен лимит отправки', [
            'limit_per_minute' => $limit,
            'last_minute' => $recent,
        ]);

        return true;
    }
}
