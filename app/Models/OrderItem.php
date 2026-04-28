<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class OrderItem extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'order_id',
        'product_id',
        'name',
        'brand_name_snapshot',
        'price',
        'base_price',
        'discount_percent',
        'final_price',
        'quantity',
        'subtotal',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'base_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'final_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item) {
            $item->fillSnapshotFields();
        });
    }

    public function fillSnapshotFields(): void
    {
        if (! $this->product_id) {
            return;
        }

        if (! empty($this->name) && ! empty($this->brand_name_snapshot)) {
            return;
        }

        $product = Product::with('brand:id,name')->find($this->product_id);
        if (! $product) {
            return;
        }

        if (empty($this->name)) {
            $this->name = (string) data_get($product, 'name');
        }
        if (empty($this->brand_name_snapshot)) {
            $brandName = data_get($product, 'brand.name');
            $this->brand_name_snapshot = $brandName !== null ? (string) $brandName : null;
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
        $this->loadMissing('order:id,user_id,number,erp_number');

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => data_get($this, 'order.number'),
            'order_erp_number' => data_get($this, 'order.erp_number'),
            'user_id' => data_get($this, 'order.user_id'),
            'product_id' => $this->product_id,
            'product_name_snapshot' => $this->name,
            'brand_name_snapshot' => $this->brand_name_snapshot,
        ];
    }

    /**
     * Поля, по которым допустим fuzzy/префиксный матч.
     *
     * @return array<int, string>
     */
    public function searchableFuzzyFields(): array
    {
        return ['product_name_snapshot', 'brand_name_snapshot'];
    }
}
