<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $uuid
 * @property string $name
 * @property array<array-key, mixed> $values
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Brand> $brands
 * @property-read int|null $brands_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SizeChart whereValues($value)
 *
 * @mixin \Eloquent
 */
class SizeChart extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'values',
    ];

    protected $casts = [
        'values' => 'array',
    ];

    public function brands(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Brand::class);
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Product::class);
    }
}
