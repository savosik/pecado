<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     */
    public static function findPrice(int $partnerId, int $productId, ?int $warehouseId = null): ?self
    {
        $query = static::where('partner_id', $partnerId)
            ->where('product_id', $productId);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->first();
    }
}
