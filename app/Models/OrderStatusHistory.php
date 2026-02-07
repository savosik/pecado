<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        if (!$this->old_status) {
            return null;
        }

        return match ($this->old_status) {
            'pending' => 'В обработке',
            'processing' => 'Обрабатывается',
            'shipped' => 'Отправлен',
            'delivered' => 'Доставлен',
            'cancelled' => 'Отменён',
            'returned' => 'Возвращён',
            default => $this->old_status,
        };
    }

    /**
     * Get the human-readable label for new status.
     */
    public function getNewStatusLabelAttribute(): string
    {
        return match ($this->new_status) {
            'pending' => 'В обработке',
            'processing' => 'Обрабатывается',
            'shipped' => 'Отправлен',
            'delivered' => 'Доставлен',
            'cancelled' => 'Отменён',
            'returned' => 'Возвращён',
            default => $this->new_status,
        };
    }
}
