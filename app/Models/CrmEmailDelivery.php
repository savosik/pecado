<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class CrmEmailDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'crm_email_id',
        'recipient',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(CrmEmail::class, 'crm_email_id');
    }
}
