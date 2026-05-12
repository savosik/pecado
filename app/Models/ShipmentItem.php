<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $shipment_id
 * @property int|null $product_id
 * @property string|null $product_name_snapshot Имя товара на момент создания строки реализации.
 * @property string|null $brand_name_snapshot Имя бренда товара на момент создания строки реализации.
 * @property string|null $order_uuid
 * @property int $quantity
 * @property numeric $price
 * @property numeric $auto_discount_percent
 * @property numeric $manual_discount_percent
 * @property numeric $total
 * @property numeric $subtotal
 * @property int|null $vat_rate
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\Product|null $product
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReturnItem> $returnItems
 * @property-read int|null $return_items_count
 * @property-read \App\Models\Shipment|null $shipment
 *
 * @method static \Database\Factories\ShipmentItemFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereAutoDiscountPercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereBrandNameSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereManualDiscountPercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereOrderUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereProductNameSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereShipmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShipmentItem whereVatRate($value)
 *
 * @mixin \Eloquent
 */
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
