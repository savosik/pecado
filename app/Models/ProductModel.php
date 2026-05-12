<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $external_id
 * @property string|null $code
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductModel whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ProductModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'code',
        'name',

    ];

    /**
     * Get the products for this model.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'model_id');
    }
}
