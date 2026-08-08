<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка расшифровки платежа — сумма, разнесённая на один документ.
 *
 * Источник правды связи — uuid, а не резолвнутый FK: платежи и документы идут
 * разными очередями без гарантии порядка. Строка живёт и с `shipment_id = null`,
 * связь доклеивается при получении документа.
 *
 * v15.16.0: документ не обязан быть реализацией. `target_type` различает три
 * случая, и от него зависит, какое поле в строке заполнено:
 *
 *   shipment → shipment_uuid, закрывает оплату накладной;
 *   order    → order_uuid, предоплата по заказу (реализации ещё нет);
 *   other    → target_uuid / target_name, сайт такую строку ни с чем не связывает.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $target_type
 * @property string|null $shipment_uuid
 * @property int|null $shipment_id
 * @property string|null $order_uuid
 * @property string|null $target_uuid
 * @property string|null $target_name
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

    /** Разнесение на реализацию — единственный тип, закрывающий оплату накладной. */
    public const TARGET_SHIPMENT = 'shipment';

    /** Предоплата по заказу клиента: реализации ещё нет, гасить нечего. */
    public const TARGET_ORDER = 'order';

    /** Прочий документ расшифровки: первичный документ, отчёт комиссионера. */
    public const TARGET_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const TARGET_TYPES = [
        self::TARGET_SHIPMENT,
        self::TARGET_ORDER,
        self::TARGET_OTHER,
    ];

    /**
     * @var array<string, string>
     */
    public const TARGET_LABELS = [
        self::TARGET_SHIPMENT => 'Реализация',
        self::TARGET_ORDER => 'Заказ (предоплата)',
        self::TARGET_OTHER => 'Прочий документ',
    ];

    protected $fillable = [
        'payment_id',
        'target_type',
        'shipment_uuid',
        'shipment_id',
        'order_uuid',
        'target_uuid',
        'target_name',
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

    /**
     * Человекочитаемое название документа расшифровки.
     *
     * Для строки `other` показывать больше нечего: сайт такие документы
     * не заводит, и `target_name` из 1С — единственное, что видит клиент.
     */
    public function documentLabel(): string
    {
        return match ($this->target_type) {
            self::TARGET_SHIPMENT => $this->shipment
                ? 'Реализация №'.($this->shipment->erp_number ?: $this->shipment->number ?: $this->shipment->id)
                : 'Реализация (ещё не загружена)',
            self::TARGET_ORDER => $this->order
                ? 'Заказ №'.($this->order->erp_number ?: $this->order->number ?: $this->order->id)
                : 'Заказ (ещё не загружен)',
            default => $this->target_name ?: 'Прочий документ',
        };
    }
}
