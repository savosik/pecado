<?php

namespace App\Models;

use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_id',
        'order_id',
        'product_id',
        'quantity',
        'reason',
        'reason_comment',
        'price',
        'subtotal',
    ];

    protected $casts = [
        'reason' => ReturnReason::class,
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    /**
     * Get the return that owns the item.
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(ProductReturn::class, 'return_id');
    }

    /**
     * Get the order for this return item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product for this return item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
