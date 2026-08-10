<?php

namespace App\Models\Delivery;

use App\Enums\Delivery\ApiShipStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала смены статусов отправки у перевозчика.
 *
 * @property int $id
 * @property string $to_status_key
 * @property \Illuminate\Support\Carbon $occurred_at
 *
 * @mixin \Eloquent
 */
class DeliveryShipmentStatusHistory extends Model
{
    use HasFactory;

    /** Статус пришёл вебхуком ORDER_STATUS. */
    public const SOURCE_WEBHOOK = 'webhook';

    /** Статус подобран периодической сверкой (страховка на случай потерянного вебхука). */
    public const SOURCE_POLL = 'poll';

    /** Статус изменён действием сотрудника склада (например, отменой заявки). */
    public const SOURCE_MANUAL = 'manual';

    /** @var list<string> */
    protected $fillable = [
        'delivery_shipment_id',
        'from_status_key',
        'to_status_key',
        'status_name',
        'provider_code',
        'source',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeliveryShipment, $this> */
    public function deliveryShipment(): BelongsTo
    {
        return $this->belongsTo(DeliveryShipment::class);
    }

    /**
     * Русская подпись перехода. Название от перевозчика приоритетнее справочника.
     */
    public function getLabelAttribute(): string
    {
        return $this->status_name
            ?: (ApiShipStatus::tryFrom($this->to_status_key)?->label() ?? $this->to_status_key);
    }
}
