<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property bool $is_active
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read int $instock_quantity
 * @property-read int $preorder_quantity
 * @property-read float $total_amount_discounted
 * @property-read float $total_amount_regular
 * @property-read int $total_quantity
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CartItem> $instockItems
 * @property-read int|null $instock_items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CartItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Order|null $order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CartItem> $preorderItems
 * @property-read int|null $preorder_items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property-read \App\Models\User $user
 *
 * @method static Builder<static>|Cart active()
 * @method static \Database\Factories\CartFactory factory($count = null, $state = [])
 * @method static Builder<static>|Cart newModelQuery()
 * @method static Builder<static>|Cart newQuery()
 * @method static Builder<static>|Cart query()
 * @method static Builder<static>|Cart whereCreatedAt($value)
 * @method static Builder<static>|Cart whereDescription($value)
 * @method static Builder<static>|Cart whereId($value)
 * @method static Builder<static>|Cart whereIsActive($value)
 * @method static Builder<static>|Cart whereName($value)
 * @method static Builder<static>|Cart whereUpdatedAt($value)
 * @method static Builder<static>|Cart whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the user that owns the cart.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the cart items for the cart.
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Выбор клиента по наградам акций: какую позицию взял, от какой отказался.
     * Сами промо-строки не хранятся — их вычисляет движок акций.
     */
    public function promotionSelections(): HasMany
    {
        return $this->hasMany(CartPromotionSelection::class);
    }

    /**
     * Get only instock items.
     */
    public function instockItems(): HasMany
    {
        return $this->hasMany(CartItem::class)->where('item_type', 'instock');
    }

    /**
     * Get only preorder items.
     */
    public function preorderItems(): HasMany
    {
        return $this->hasMany(CartItem::class)->where('item_type', 'preorder');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'cart_items')
            ->withPivot('quantity', 'price', 'item_type', 'warehouse_id')
            ->withTimestamps();
    }

    /**
     * Get the order associated with the cart.
     */
    public function order(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Order::class);
    }

    // ────────────────────────────────────────────
    // Scopes
    // ────────────────────────────────────────────

    /**
     * Scope a query to only include active carts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ────────────────────────────────────────────
    // Accessors
    // ────────────────────────────────────────────

    /**
     * Total quantity of all items.
     */
    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /**
     * Total quantity of instock items.
     */
    public function getInstockQuantityAttribute(): int
    {
        return (int) $this->items->where('item_type', 'instock')->sum('quantity');
    }

    /**
     * Total quantity of preorder items.
     */
    public function getPreorderQuantityAttribute(): int
    {
        return (int) $this->items->where('item_type', 'preorder')->sum('quantity');
    }

    /**
     * Total regular amount (sum of quantity * price for all items).
     */
    public function getTotalAmountRegularAttribute(): float
    {
        return (float) $this->items->sum(function (CartItem $item) {
            return $item->quantity * ($item->price ?? 0);
        });
    }

    /**
     * Total discounted amount (will be calculated via PriceService in controller/service layer).
     * Here we use stored price as a fallback.
     */
    public function getTotalAmountDiscountedAttribute(): float
    {
        return $this->total_amount_regular;
    }

    // ────────────────────────────────────────────
    // Methods
    // ────────────────────────────────────────────

    /**
     * Add a product to the cart (or update quantity if exists).
     */
    public function addProduct(Product $product, int $qty, string $type = 'instock', ?int $warehouseId = null, ?float $price = null): CartItem
    {
        $item = $this->items()
            ->where('product_id', $product->id)
            ->where('item_type', $type)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($item) {
            $data = ['quantity' => $item->quantity + $qty];
            if ($price !== null) {
                $data['price'] = $price;
            }
            $item->update($data);

            return $item->fresh();
        }

        return $this->items()->create([
            'product_id' => $product->id,
            'quantity' => $qty,
            'price' => $price,
            'item_type' => $type,
            'warehouse_id' => $warehouseId,
        ]);
    }

    /**
     * Remove a product from the cart.
     */
    public function removeProduct(Product $product, string $type = 'instock', ?int $warehouseId = null): bool
    {
        return (bool) $this->items()
            ->where('product_id', $product->id)
            ->where('item_type', $type)
            ->where('warehouse_id', $warehouseId)
            ->delete();
    }

    /**
     * Clear all items from the cart.
     */
    public function clear(): int
    {
        return $this->items()->delete();
    }

    /**
     * Check if the cart is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items()->count() === 0;
    }
}
