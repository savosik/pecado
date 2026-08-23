<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Факт отправки письма конкретному адресу.
 *
 * Существует ради одной гарантии: письмо с одним и тем же id не уходит
 * на один и тот же адрес дважды — сколько бы правил его ни поймало
 * и сколько бы раз задание очереди ни повторилось.
 *
 * @property int $id
 * @property int $crm_email_id
 * @property string $recipient
 * @property string $channel
 * @property string|null $track_token
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $clicked_at
 * @property int $opens_count
 * @property int $clicks_count
 */
class CrmEmailDelivery extends Model
{
    public const UPDATED_AT = null;

    /** Адресат указан в основном поле письма. */
    public const CHANNEL_TO = 'to';

    /** Адресат в копии. */
    public const CHANNEL_CC = 'cc';

    protected $fillable = [
        'crm_email_id',
        'recipient',
        'channel',
        'track_token',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(CrmEmail::class, 'crm_email_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CrmEmailEvent::class, 'delivery_id');
    }

    /**
     * Открытие зафиксировано.
     *
     * Именно «зафиксировано», а не «прочитано»: почтовый клиент мог не загрузить
     * картинку, и тогда прочитанное письмо выглядит непрочитанным.
     */
    public function wasOpened(): bool
    {
        return $this->opened_at !== null;
    }

    /**
     * Переход по ссылке — сигнал куда честнее открытия: прокси кликают редко,
     * человек кликает осознанно.
     */
    public function wasClicked(): bool
    {
        return $this->clicked_at !== null;
    }
}
