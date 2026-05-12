<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WishlistItem whereUserId($value)
 *
 * @mixin \Eloquent
 */
class WishlistItem extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
    ];

    /**
     * Get the user that owns the wishlist item.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the product that is in the wishlist.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
