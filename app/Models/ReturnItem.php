<?php

namespace App\Models;

use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class ReturnItem extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'return_id',
        'shipment_item_id',
        'shipment_id',
        'product_id',
        'product_name_snapshot',
        'brand_name_snapshot',
        'quantity',
        'reason',
        'reason_comment',
        'price',
        'subtotal',
    ];

    protected $casts = [
        'reason' => ReturnReason::class,
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (ReturnItem $item) {
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

    public function return(): BelongsTo
    {
        return $this->belongsTo(ProductReturn::class, 'return_id');
    }

    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
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
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing('return:id,user_id,erp_number');

        return [
            'id' => $this->id,
            'return_id' => $this->return_id,
            'return_erp_number' => data_get($this, 'return.erp_number'),
            'user_id' => data_get($this, 'return.user_id'),
            'product_id' => $this->product_id,
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
