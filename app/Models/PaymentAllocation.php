<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка расшифровки платежа — сумма, разнесённая на одну реализацию.
 *
 * Источник правды связи — `shipment_uuid`, а не `shipment_id`: платежи и реализации
 * идут разными очередями без гарантии порядка. Строка живёт и с `shipment_id = null`,
 * связь доклеивается при получении реализации.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $shipment_uuid
 * @property int|null $shipment_id
 * @property string|null $order_uuid
 * @property numeric $amount
 * @property int|null $line_number
 * @property-read \App\Models\Payment|null $payment
 * @property-read \App\Models\Shipment|null $shipment
 * @property-read \App\Models\Order|null $order
 *
 * @method static \Database\Factories\PaymentAllocationFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'shipment_uuid',
        'shipment_id',
        'order_uuid',
        'amount',
        'line_number',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Реализация по резолвнутому FK. Null, пока реализация не приехала из 1С.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Заказ из строки расшифровки — мягкая связь по uuid, как у shipment_items.
     * FK нет: заказ мог не приехать на сайт.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid');
    }
}
