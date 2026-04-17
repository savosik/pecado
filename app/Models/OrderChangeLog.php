<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChangeLog extends Model
{
    protected $fillable = [
        'order_id',
        'type',
        'summary',
        'changes',
        'source',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
