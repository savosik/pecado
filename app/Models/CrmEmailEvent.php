<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Событие по отправленному письму: открытие или переход по ссылке.
 *
 * Хранится детально, а не только счётчиком: разбирая «читает ли нас этот
 * клиент», важно отличить живого человека от предзагрузки почтового клиента —
 * а это видно только по времени, адресу и User-Agent.
 *
 * @property int $id
 * @property int $delivery_id
 * @property string $type
 * @property string|null $url
 */
class CrmEmailEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_OPEN = 'open';

    public const TYPE_CLICK = 'click';

    protected $fillable = [
        'delivery_id',
        'type',
        'url',
        'ip',
        'user_agent',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(CrmEmailDelivery::class, 'delivery_id');
    }
}
