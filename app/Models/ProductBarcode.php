<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $barcode
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode whereBarcode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductBarcode whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductBarcode extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'barcode',
    ];

    /**
     * Get the product that owns this barcode.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
