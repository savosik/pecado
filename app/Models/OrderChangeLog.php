<?php

namespace App\Models;

use App\Events\EntityChanged;
use App\Subscriptions\EntityChangeNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string $summary
 * @property array<array-key, mixed> $changes
 * @property string $source
 * @property int|null $user_id
 * @property numeric|null $old_total
 * @property numeric|null $new_total
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereNewTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereOldTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereUserId($value)
 *
 * @mixin \Eloquent
 */
class OrderChangeLog extends Model
{
    protected $fillable = [
        'order_id',
        'type',
        'summary',
        'changes',
        'source',
        'user_id',
        'old_total',
        'new_total',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'old_total' => 'decimal:2',
            'new_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Единая точка триггера уведомлений подписчикам раздела «Заказы»:
        // сюда попадают изменения из всех источников (ERP / админка / API),
        // т.к. все они создают OrderChangeLog через OrderChangeLogger.
        static::created(function (self $log): void {
            $order = $log->order;

            if (! $order || blank($order->user_id)) {
                return;
            }

            $number = $order->erp_number ?: $order->number;

            EntityChanged::dispatch(new EntityChangeNotice(
                section: 'orders',
                ownerUserId: (int) $order->user_id,
                title: sprintf('Изменение по заказу %s — Pecado.ru', $number),
                body: (string) $log->summary,
                url: url(route('cabinet.orders.show', $order, false)),
                entityLabel: "Заказ {$number}",
            ));
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
