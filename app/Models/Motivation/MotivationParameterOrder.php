<?php

namespace App\Models\Motivation;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Приказ с числовыми параметрами Положения о мотивации (Приложение № 1).
 *
 * Действующий набор — приказ с максимальной датой начала действия, не превышающей
 * расчётный период. Приказы прошлых периодов не правятся: расчёт читается по тому,
 * что действовало тогда.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $effective_from
 * @property string|null $order_number
 * @property \Illuminate\Support\Carbon|null $order_date
 * @property array<string, mixed> $values
 * @property string|null $comment
 * @property int|null $author_id
 * @property-read User|null $author
 */
class MotivationParameterOrder extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationParameterOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'effective_from',
        'order_number',
        'order_date',
        'values',
        'comment',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'order_date' => 'date',
            'values' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Приказ, действовавший в расчётном периоде.
     */
    public static function effectiveFor(Carbon $month): ?self
    {
        return self::query()
            ->whereDate('effective_from', '<=', $month->copy()->startOfMonth())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEffectiveAt(Builder $query, Carbon $month): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $month->copy()->startOfMonth())
            ->orderByDesc('effective_from');
    }
}
