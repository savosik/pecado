<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'product_id',
        'order_uuid',
        'quantity',
        'price',
        'auto_discount_percent',
        'manual_discount_percent',
        'total',
        'subtotal',
        'vat_rate',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'auto_discount_percent' => 'decimal:2',
        'manual_discount_percent' => 'decimal:2',
        'total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
        'vat_rate' => 'integer',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Заказ, по которому была создана позиция (если он существует на сайте).
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_uuid', 'uuid');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }
}
