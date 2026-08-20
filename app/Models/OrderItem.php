<?php

namespace App\Models;

use App\Enums\Order\CancelSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $line_number Номер строки в табличной части «Товары» документа 1С — ключ сопоставления при обновлении заказа (v15.16.0)
 * @property bool $cancelled Строка отменена в 1С при недоборе: показывается клиенту, но не входит в сумму заказа (v15.16.0)
 * @property \Illuminate\Support\Carbon|null $cancelled_at Когда сайт принял отмену строки из 1С — дата в журнале недоборов
 * @property CancelSource|null $cancel_source Метка менеджера: склад снял при сборке или клиент отказался
 * @property int|null $cancel_source_user_id Кто поставил метку источника отмены
 * @property \Illuminate\Support\Carbon|null $cancel_source_at Когда поставлена метка
 * @property string|null $cancel_note Комментарий менеджера к отмене
 * @property int|null $product_id
 * @property int|null $product_defect_id
 * @property string|null $defect_description
 * @property string $name
 * @property string|null $brand_name_snapshot Имя бренда товара на момент создания строки. Используется для fuzzy-поиска без JOIN.
 * @property numeric $price
 * @property numeric|null $base_price
 * @property numeric $discount_percent
 * @property numeric|null $final_price
 * @property int $quantity
 * @property numeric $subtotal
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\Product|null $product
 * @property-read \App\Models\ProductDefect|null $productDefect
 * @property-read \App\Models\User|null $cancelSourceUser
 *
 * @method static \Database\Factories\OrderItemFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereBasePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereBrandNameSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereDiscountPercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereFinalPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class OrderItem extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'order_id',
        'line_number',
        'cancelled',
        'product_id',
        'product_defect_id',
        'defect_description',
        'promotion_rule_id',
        'promo_kind',
        'name',
        'brand_name_snapshot',
        'price',
        'base_price',
        'discount_percent',
        'final_price',
        'quantity',
        'subtotal',
        'cancelled_at',
        'cancel_source',
        'cancel_source_user_id',
        'cancel_source_at',
        'cancel_note',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'base_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'final_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
        'line_number' => 'integer',
        'cancelled' => 'boolean',
        'cancelled_at' => 'datetime',
        'cancel_source' => CancelSource::class,
        'cancel_source_at' => 'datetime',
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
     * Партия некондиции — только у позиций заказов type = defect.
     *
     * Резерв партии считается именно по этим строкам, см. DefectStockService.
     */
    public function productDefect(): BelongsTo
    {
        return $this->belongsTo(ProductDefect::class);
    }

    /**
     * Сотрудник, разметивший источник отмены строки в журнале недоборов.
     */
    public function cancelSourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancel_source_user_id');
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
