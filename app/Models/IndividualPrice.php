<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IndividualPrice extends Model
{
    public $incrementing = false;
    public $timestamps = false;

    protected $table = 'individual_prices';

    protected $fillable = [
        'partner_uuid',
        'product_uuid',
        'warehouse_uuid',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /**
     * Получить индивидуальную цену для конкретного партнёра, товара и склада.
     */
    public static function findPrice(string $partnerUuid, string $productUuid, string $warehouseUuid): ?self
    {
        return static::where('partner_uuid', $partnerUuid)
            ->where('product_uuid', $productUuid)
            ->where('warehouse_uuid', $warehouseUuid)
            ->first();
    }
}
