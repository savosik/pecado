<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Решение движка по одному адресату одного сигнала.
 *
 * Показывает и отрицательные исходы: письмо не ушло, потому что адрес в
 * стоп-листе, сработал троттлинг или режим теневой. Именно это отвечает
 * менеджеру на «почему клиенту не пришло» без обращения к разработчику.
 *
 * @property string $signal_uuid
 * @property string $recipient
 * @property string $status
 * @property string|null $skip_reason
 */
class NotificationDelivery extends Model
{
    use HasFactory, Prunable;

    /** Поставлено в очередь. */
    public const STATUS_QUEUED = 'queued';

    /** Письмо сдано транспорту. */
    public const STATUS_SENT = 'sent';

    /** Не отправлено по решению движка. */
    public const STATUS_SKIPPED = 'skipped';

    /** Отправка упала. */
    public const STATUS_FAILED = 'failed';

    public const REASON_DUPLICATE = 'duplicate';

    public const REASON_THROTTLED = 'throttled';

    public const REASON_UNSUBSCRIBED = 'unsubscribed';

    public const REASON_NO_CONSENT = 'no_consent';

    public const REASON_SUPPRESSED = 'suppressed';

    public const REASON_INVALID_EMAIL = 'invalid_email';

    public const REASON_SHADOW = 'shadow';

    public const REASON_DRY_RUN = 'dry_run';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_TOO_OLD = 'too_old';

    public const REASON_FEATURE_OFF = 'feature_off';

    /**
     * Русские подписи исходов — их читает менеджер в журнале.
     */
    public static function skipReasonLabel(?string $reason): string
    {
        return match ($reason) {
            self::REASON_DUPLICATE => 'Адрес уже получил это событие',
            self::REASON_THROTTLED => 'Слишком часто по этому правилу',
            self::REASON_UNSUBSCRIBED => 'Адресат отписался',
            self::REASON_NO_CONSENT => 'Нет согласия на рассылки',
            self::REASON_SUPPRESSED => 'Адрес в стоп-листе',
            self::REASON_INVALID_EMAIL => 'Некорректный адрес',
            self::REASON_SHADOW => 'Теневой режим — отправка выключена',
            self::REASON_DRY_RUN => 'Предпросмотр',
            self::REASON_RATE_LIMITED => 'Превышен лимит отправки',
            self::REASON_TOO_OLD => 'Событие слишком старое',
            self::REASON_FEATURE_OFF => 'Раздел уведомлений выключен',
            default => (string) $reason,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => 'В очереди',
            self::STATUS_SENT => 'Отправлено',
            self::STATUS_SKIPPED => 'Пропущено',
            self::STATUS_FAILED => 'Ошибка отправки',
            default => $status,
        };
    }

    protected $fillable = [
        'signal_uuid',
        'event_key',
        'notification_rule_id',
        'rule_name',
        'client_user_id',
        'company_id',
        'contact_id',
        'channel',
        'recipient',
        'recipient_kind',
        'status',
        'skip_reason',
        'subject',
        'message_id',
        'error',
        'queued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'notification_rule_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(NotificationSignal::class, 'signal_uuid', 'uuid');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'contact_id');
    }

    /**
     * Живут дольше журнала писем: «когда мы перестали слать этому бухгалтеру»
     * спрашивают и через год, а строка компактная — тела письма здесь нет.
     *
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function prunable()
    {
        $days = (int) config('notification_pulse.retention.deliveries_days', 365);

        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}
