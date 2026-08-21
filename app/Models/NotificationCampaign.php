<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Рассылка по сегменту клиентов.
 *
 * @property string $status
 * @property array|null $segment
 */
class NotificationCampaign extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_SCHEDULED => 'Запланирована',
            self::STATUS_SENDING => 'Отправляется',
            self::STATUS_SENT => 'Отправлена',
            self::STATUS_CANCELLED => 'Отменена',
            default => $status,
        };
    }

    protected $fillable = [
        'name',
        'description',
        'segment',
        'subject',
        'body_html',
        'crm_email_template_id',
        'status',
        'scheduled_at',
        'started_at',
        'finished_at',
        'recipients_total',
        'recipients_sent',
        'recipients_skipped',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'segment' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationCampaignRecipient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Кампанию можно править, пока она не ушла.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }
}
