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
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError whereDirection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError whereErrors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError whereMessageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ErpValidationError wherePayload($value)
 *
 * @mixin \Eloquent
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
