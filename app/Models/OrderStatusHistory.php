<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string|null $old_status
 * @property string $new_status
 * @property int|null $user_id
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $new_status_label
 * @property-read string|null $old_status_label
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereNewStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereOldStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderStatusHistory whereUserId($value)
 *
 * @mixin \Eloquent
 */
class OrderStatusHistory extends Model
{
    protected $fillable = [
        'order_id',
        'old_status',
        'new_status',
        'user_id',
        'comment',
    ];

    /**
     * Get the order that owns the status history.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who changed the status.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the human-readable label for old status.
     */
    public function getOldStatusLabelAttribute(): ?string
    {
        return $this->old_status ? $this->labelFor($this->old_status) : null;
    }

    /**
     * Get the human-readable label for new status.
     */
    public function getNewStatusLabelAttribute(): string
    {
        return $this->labelFor($this->new_status);
    }

    private function labelFor(string $statusValue): string
    {
        $status = OrderStatus::tryFrom($statusValue);

        return $status?->label() ?? $statusValue;
    }
}
