<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class ShipmentItem extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'shipment_id',
        'product_id',
        'product_name_snapshot',
        'brand_name_snapshot',
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

    protected static function booted(): void
    {
        static::creating(function (ShipmentItem $item) {
            $item->fillSnapshotFields();
        });
    }

    public function fillSnapshotFields(): void
    {
        if (! $this->product_id) {
            return;
        }

        if (! empty($this->product_name_snapshot) && ! empty($this->brand_name_snapshot)) {
            return;
        }

        $product = Product::with('brand:id,name')->find($this->product_id);
        if (! $product) {
            return;
        }

        if (empty($this->product_name_snapshot)) {
            $this->product_name_snapshot = (string) data_get($product, 'name');
        }
        if (empty($this->brand_name_snapshot)) {
            $brandName = data_get($product, 'brand.name');
            $this->brand_name_snapshot = $brandName !== null ? (string) $brandName : null;
        }
    }

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

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing('shipment:id,user_id,number,erp_number');

        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => data_get($this, 'shipment.number'),
            'shipment_erp_number' => data_get($this, 'shipment.erp_number'),
            'user_id' => data_get($this, 'shipment.user_id'),
            'product_id' => $this->product_id,
            'order_uuid' => $this->order_uuid,
            'product_name_snapshot' => $this->product_name_snapshot,
            'brand_name_snapshot' => $this->brand_name_snapshot,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function searchableFuzzyFields(): array
    {
        return ['product_name_snapshot', 'brand_name_snapshot'];
    }
}
