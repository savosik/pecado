<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $partner_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property numeric $price
 * @property string $updated_at
 * @property-read \App\Models\User|null $partner
 * @property-read \App\Models\Product|null $product
 * @property-read \App\Models\Warehouse|null $warehouse
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice wherePartnerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IndividualPrice whereWarehouseId($value)
 *
 * @mixin \Eloquent
 */
class IndividualPrice extends Model
{
    protected $connection = 'prices';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'individual_prices';

    protected $fillable = [
        'partner_id',
        'product_id',
        'warehouse_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Получить индивидуальную цену для конкретного партнёра и товара.
     * Без склада — детерминированно минимальный warehouse_id (то же правило,
     * что в IndividualPriceProxy::findPrice / loadPriceMap).
     */
    public static function findPrice(int $partnerId, int $productId, ?int $warehouseId = null): ?self
    {
        $query = static::where('partner_id', $partnerId)
            ->where('product_id', $productId);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        } else {
            $query->orderBy('warehouse_id');
        }

        return $query->first();
    }
}
