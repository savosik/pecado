<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Лог ошибок валидации ERP-сообщений.
 *
 * Записи создаются автоматически при невалидном payload
 * входящих (1С → Сайт) и исходящих (Сайт → 1С) сообщений.
 *
 * @property int $id
 * @property string $event
 * @property string $direction incoming | outgoing
 * @property string|null $message_id
 * @property array $errors
 * @property array|null $payload
 * @property \Carbon\Carbon $created_at
 */
class ErpValidationError extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event',
        'direction',
        'message_id',
        'errors',
        'payload',
    ];

    protected $casts = [
        'errors' => 'array',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
