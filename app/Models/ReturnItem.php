<?php

namespace App\Models;

use App\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $return_id
 * @property int $shipment_item_id
 * @property int $shipment_id
 * @property int $product_id
 * @property string|null $product_name_snapshot Имя товара на момент создания возврата. Сохраняется при удалении товара из каталога.
 * @property string|null $brand_name_snapshot Имя бренда товара на момент создания возврата.
 * @property int $quantity
 * @property ReturnReason $reason
 * @property string|null $reason_comment
 * @property numeric $price
 * @property numeric $subtotal
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\ProductReturn|null $return
 * @property-read \App\Models\Shipment|null $shipment
 * @property-read \App\Models\ShipmentItem $shipmentItem
 *
 * @method static \Database\Factories\ReturnItemFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereBrandNameSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereProductNameSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereReasonComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereReturnId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereShipmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereShipmentItemId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReturnItem whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
