<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $external_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Region> $preorderRegions
 * @property-read int|null $preorder_regions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Region> $primaryRegions
 * @property-read int|null $primary_regions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Database\Factories\WarehouseFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Warehouse whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'external_id',
        'is_defect',
        'is_promo_sample',
    ];

    protected function casts(): array
    {
        return [
            'is_defect' => 'boolean',
            'is_promo_sample' => 'boolean',
        ];
    }

    /**
     * Склад некондиции — с него отгружаются заказы уценки.
     *
     * Такой склад не входит в регионы, поэтому его остатки не попадают
     * в наличие и предзаказ на витрине (см. StockService).
     */
    public function scopeDefect(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_defect', true);
    }

    /**
     * Склад рекламных образцов («Москва реклама») — с него отгружаются пробники.
     *
     * Такой склад не входит в регионы, поэтому его остатки не попадают в наличие
     * и предзаказ на витрине (см. StockService). Для рекламного склада это
     * не оптимизация, а требование: пробник не должен продаваться как обычный
     * товар — он выдаётся только движком акций и в накладную клиенту не попадает.
     */
    public function scopePromoSample(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_promo_sample', true);
    }

    /**
     * Get the products with stock in the warehouse.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_warehouse')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Get the regions where this warehouse is primary.
     */
    public function primaryRegions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'region_warehouse')
            ->wherePivot('type', 'primary')
            ->withTimestamps();
    }

    /**
     * Get the regions where this warehouse is for preorder.
     */
    public function preorderRegions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'region_warehouse')
            ->wherePivot('type', 'preorder')
            ->withTimestamps();
    }
}
