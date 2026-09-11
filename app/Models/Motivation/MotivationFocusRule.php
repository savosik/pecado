<?php

namespace App\Models\Motivation;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Правило включения позиций в Фокус-перечень: бренд, категория или отдельный товар.
 *
 * Одно правило на бренд покрывает сотни товаров, поэтому правил единицы, а развёрнутый
 * состав живёт в снимке на период.
 *
 * @property int $id
 * @property string $scope
 * @property int $target_id
 * @property string|null $rate
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon|null $ends_on
 * @property string|null $order_number
 * @property \Illuminate\Support\Carbon|null $order_date
 * @property string|null $comment
 * @property int|null $author_id
 */
class MotivationFocusRule extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationFocusRuleFactory> */
    use HasFactory;

    public const SCOPE_BRAND = 'brand';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_PRODUCT = 'product';

    protected $fillable = [
        'scope',
        'target_id',
        'rate',
        'starts_on',
        'ends_on',
        'order_number',
        'order_date',
        'comment',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'rate' => 'decimal:5',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'order_date' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<MotivationFocusSnapshotItem, $this> */
    public function snapshotItems(): HasMany
    {
        return $this->hasMany(MotivationFocusSnapshotItem::class, 'rule_id');
    }

    /**
     * Правила, действующие в указанный день.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $day): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day));
    }
}
