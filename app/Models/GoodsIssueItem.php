<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка расходного ордера — «Товары по распоряжениям» (US-20).
 *
 * Ссылки на товар и заказ nullable: ордер может уехать по номенклатуре, которой нет
 * в каталоге сайта, и по заказу, который сайту не приезжал. Рядом лежат снимки имени
 * и номера из 1С — без них строка нечитаема для кладовщика.
 *
 * @property int $id
 * @property int $goods_issue_id
 * @property int|null $line_number
 * @property int|null $product_id
 * @property string $product_uuid
 * @property string|null $product_name
 * @property int|null $order_id
 * @property string|null $order_uuid
 * @property string|null $order_number
 * @property \Illuminate\Support\Carbon|null $order_date
 * @property numeric $quantity
 * @property string|null $unit
 * @property int|null $package_number
 * @property-read string $product_label
 * @property-read \App\Models\GoodsIssue $goodsIssue
 * @property-read \App\Models\Product|null $product
 * @property-read \App\Models\Order|null $order
 *
 * @method static \Database\Factories\GoodsIssueItemFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class GoodsIssueItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_issue_id',
        'line_number',
        'product_id',
        'product_uuid',
        'product_name',
        'order_id',
        'order_uuid',
        'order_number',
        'order_date',
        'quantity',
        'unit',
        'package_number',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<GoodsIssue, $this> */
    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Наименование для показа: актуальное из каталога, иначе снимок из 1С.
     */
    public function getProductLabelAttribute(): string
    {
        return $this->product?->name
            ?? $this->product_name
            ?? 'Товар не найден в каталоге';
    }
}
