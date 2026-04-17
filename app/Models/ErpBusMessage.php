<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Лог-запись сообщения, прошедшего через шину ERP (RabbitMQ).
 *
 * Хранит входящие (1С → Сайт) и исходящие (Сайт → 1С) сообщения
 * с полным payload для отладки и мониторинга.
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
