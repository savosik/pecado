<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Точечное отклонение режима «Заказы в резерве» по партнёру (res-05).
 *
 * Хранятся только отклонения от умолчания: нет строки — действуют умолчания
 * (участие по флагу 1С users.reserve_allowed, срок из config/order_reserve.php).
 * Сайт этим сужает охват поверх флага 1С, но не расширяет.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $disabled
 * @property int|null $hours
 * @property int|null $created_by
 */
class OrderReserveOverride extends Model
{
    protected $fillable = [
        'user_id',
        'disabled',
        'hours',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'disabled' => 'boolean',
            'hours' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
