<?php

namespace App\Models;

use App\Enums\Substitution\CandidateKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Кандидат на замену по одной отменённой строке заказа.
 *
 * Заполнено ровно одно из двух: product_id (обычный товар) или
 * product_defect_id (партия уценки). Кандидат обязан нести причину —
 * замена без объяснения не продаётся.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $source_order_item_id
 * @property int|null $product_id
 * @property int|null $product_defect_id
 * @property CandidateKind $kind
 * @property string $reason
 * @property string|null $price_snapshot
 * @property int $suggested_quantity
 * @property \Illuminate\Support\Carbon|null $removed_by_manager_at
 * @property bool $chosen
 * @property int|null $chosen_quantity
 */
class SubstitutionOfferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'source_order_item_id',
        'product_id',
        'product_defect_id',
        'kind',
        'reason',
        'price_snapshot',
        'suggested_quantity',
        'removed_by_manager_at',
        'chosen',
        'chosen_quantity',
    ];

    protected function casts(): array
    {
        return [
            'kind' => CandidateKind::class,
            'price_snapshot' => 'decimal:2',
            'removed_by_manager_at' => 'datetime',
            'chosen' => 'boolean',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(SubstitutionOffer::class, 'offer_id');
    }

    public function sourceOrderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'source_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productDefect(): BelongsTo
    {
        return $this->belongsTo(ProductDefect::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubstitutionEvent::class, 'offer_item_id');
    }

    /**
     * Кандидаты, которых менеджер не снимал — их видит клиент.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_by_manager_at');
    }
}
