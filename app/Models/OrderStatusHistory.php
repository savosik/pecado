<?php

namespace App\Models;

use App\Enums\OrderStatus;
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
