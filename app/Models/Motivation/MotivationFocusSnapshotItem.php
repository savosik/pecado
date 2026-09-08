<?php

namespace App\Models\Motivation;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Позиция Фокус-перечня в конкретном расчётном периоде со ставкой, действовавшей тогда.
 *
 * Снимок делает расчёт прошлого месяца воспроизводимым: правка правил задним числом
 * его не меняет.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $period_month
 * @property int $product_id
 * @property int|null $rule_id
 * @property string $rate
 */
class MotivationFocusSnapshotItem extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationFocusSnapshotItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'period_month',
        'product_id',
        'rule_id',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'rate' => 'decimal:5',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<MotivationFocusRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(MotivationFocusRule::class, 'rule_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPeriod(Builder $query, Carbon $month): Builder
    {
        return $query->whereDate('period_month', $month->copy()->startOfMonth());
    }
}
