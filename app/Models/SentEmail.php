<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала исходящих писем — факт отправки, а не её замысел.
 *
 * @property int $id
 * @property string $recipient
 * @property string|null $subject
 * @property string|null $source
 * @property int|null $client_user_id
 * @property int|null $recipient_user_id
 * @property string|null $message_id
 * @property \Illuminate\Support\Carbon $sent_at
 */
class SentEmail extends Model
{
    use HasFactory;
    use Prunable;

    protected $fillable = [
        'recipient',
        'subject',
        'source',
        'client_user_id',
        'contact_id',
        'recipient_user_id',
        'message_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'client_user_id' => 'integer',
            'recipient_user_id' => 'integer',
        ];
    }

    /**
     * Журнал чистится сам.
     *
     * Письма идут потоком, и без ретенции таблица повторила бы судьбу
     * `pulse_*` и `erp_bus_messages`, занимавших большую часть боевой базы
     * (Pulse с его 5,8 GB удалён 2026-08-12 именно поэтому).
     * Срок настраивается: `notifications.mail.journal_retention_days`,
     * 0 или отрицательное — не чистить.
     */
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        $days = (int) config('notifications.mail.journal_retention_days', 180);

        if ($days <= 0) {
            // Пустая выборка: `whereRaw('0=1')` честнее, чем не объявлять
            // prunable() вовсе — команда отработает и ничего не удалит.
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('sent_at', '<', now()->subDays($days));
    }

    /**
     * Клиент, к жизни которого относится письмо.
     *
     * Не обязательно получатель: письмо менеджеру о заказе адресовано менеджеру,
     * но событие относится к клиенту, и в ленте оно должно быть у клиента.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
