<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int $quantity
 * @property numeric|null $price
 * @property string $item_type
 * @property int|null $warehouse_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $product_defect_id
 * @property-read \App\Models\Cart $cart
 * @property-read float $total_amount
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\ProductDefect|null $productDefect
 * @property-read \App\Models\Warehouse|null $warehouse
 *
 * @method static \Database\Factories\CartItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|CartItem instock()
 * @method static Builder<static>|CartItem newModelQuery()
 * @method static Builder<static>|CartItem newQuery()
 * @method static Builder<static>|CartItem preorder()
 * @method static Builder<static>|CartItem query()
 * @method static Builder<static>|CartItem whereCartId($value)
 * @method static Builder<static>|CartItem whereCreatedAt($value)
 * @method static Builder<static>|CartItem whereId($value)
 * @method static Builder<static>|CartItem whereItemType($value)
 * @method static Builder<static>|CartItem wherePrice($value)
 * @method static Builder<static>|CartItem whereProductId($value)
 * @method static Builder<static>|CartItem whereQuantity($value)
 * @method static Builder<static>|CartItem whereUpdatedAt($value)
 * @method static Builder<static>|CartItem whereWarehouseId($value)
 *
 * @mixin \Eloquent
 */
class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'product_defect_id',
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

    /**
     * Партия некондиции — только у позиций с item_type = defect.
     */
    public function productDefect(): BelongsTo
    {
        return $this->belongsTo(ProductDefect::class);
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

    /**
     * Scope a query to only include defect (уценка) items.
     */
    public function scopeDefect(Builder $query): Builder
    {
        return $query->where('item_type', 'defect');
    }

    /**
     * Scope: всё, кроме уценки — обычные instock/preorder позиции.
     *
     * Spillover-логика (setProductQuantity и родня) удаляет и пересоздаёт строки
     * товара пачкой; уценка привязана к конкретной партии и живёт отдельно,
     * поэтому её нужно исключать из таких операций.
     */
    public function scopeExcludingDefect(Builder $query): Builder
    {
        return $query->where('item_type', '!=', 'defect');
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

    /**
     * Позиция уценки: цена берётся из партии, spillover instock/preorder её не касается.
     */
    public function isDefect(): bool
    {
        return $this->item_type === 'defect';
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
