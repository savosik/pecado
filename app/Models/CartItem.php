<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price',
        'item_type',
        'warehouse_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'item_type' => 'string',
    ];

    /**
     * Get the cart that owns the item.
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Get the product for the cart item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the warehouse for the cart item.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ────────────────────────────────────────────
    // Scopes
    // ────────────────────────────────────────────

    /**
     * Scope a query to only include instock items.
     */
    public function scopeInstock(Builder $query): Builder
    {
        return $query->where('item_type', 'instock');
    }

    /**
     * Scope a query to only include preorder items.
     */
    public function scopePreorder(Builder $query): Builder
    {
        return $query->where('item_type', 'preorder');
    }

    // ────────────────────────────────────────────
    // Methods
    // ────────────────────────────────────────────

    /**
     * Check if this item is instock.
     */
    public function isInstock(): bool
    {
        return $this->item_type === 'instock';
    }

    /**
     * Check if this item is preorder.
     */
    public function isPreorder(): bool
    {
        return $this->item_type === 'preorder';
    }

    // ────────────────────────────────────────────
    // Accessors
    // ────────────────────────────────────────────

    /**
     * Total amount for this item = quantity * price.
     */
    public function getTotalAmountAttribute(): float
    {
        return (float) ($this->quantity * ($this->price ?? 0));
    }
}
