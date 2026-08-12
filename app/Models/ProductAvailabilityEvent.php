<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Переход доступности товара: появился в продаже или кончился.
 *
 * Записываются именно переходы, а не снимки остатка: `stock.updated` идёт
 * потоком, и строка на каждое сообщение съела бы базу — тот же исход, что
 * у таблиц Pulse, из-за которого их снесли.
 *
 * @property int $id
 * @property int $product_id
 * @property string $event
 * @property int $quantity
 * @property Carbon $happened_at
 * @property int|null $missing_days
 */
class ProductAvailabilityEvent extends Model
{
    use HasFactory;

    public const IN_STOCK = 'in_stock';

    public const OUT_OF_STOCK = 'out_of_stock';

    protected $fillable = [
        'product_id',
        'event',
        'quantity',
        'happened_at',
        'missing_days',
    ];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
            'quantity' => 'integer',
            'missing_days' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Товары, вернувшиеся в продажу за период.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBackInStockSince(Builder $query, Carbon $since): Builder
    {
        return $query
            ->where('event', self::IN_STOCK)
            ->where('happened_at', '>=', $since);
    }
}
