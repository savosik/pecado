<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Лог-запись сообщения, прошедшего через шину ERP (RabbitMQ).
 *
 * Хранит входящие (1С → Сайт) и исходящие (Сайт → 1С) сообщения
 * с полным payload для отладки и мониторинга.
 *
 * @property int $id
 * @property string $direction
 * @property string|null $routing_key Очередь / routing key
 * @property string $event Тип события (partner.created, order.updated, ...)
 * @property string|null $message_id message_id из payload
 * @property array<array-key, mixed> $payload Полный JSON payload
 * @property string $status
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage failed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage incoming()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage outgoing()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereDirection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereMessageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereRoutingKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpBusMessage whereStatus($value)
 *
 * @mixin \Eloquent
 */
class ErpBusMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'direction',
        'routing_key',
        'event',
        'message_id',
        'payload',
        'status',
        'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Scope: только входящие.
     */
    public function scopeIncoming($query)
    {
        return $query->where('direction', 'incoming');
    }

    /**
     * Scope: только исходящие.
     */
    public function scopeOutgoing($query)
    {
        return $query->where('direction', 'outgoing');
    }

    /**
     * Scope: только ошибки.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
