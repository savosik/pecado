<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Шапка последнего применённого снимка ожидаемых поступлений товара (v16.16.0).
 *
 * Нужна ради `calculated_at`: снимок, собранный в 1С раньше уже применённого,
 * отбрасывается. Шапка остаётся и после очистки — иначе опоздавший непустой
 * снимок вернул бы ожидания, которых уже нет.
 *
 * @property int $id
 * @property int $product_id
 * @property \Illuminate\Support\Carbon $calculated_at
 * @property \Illuminate\Support\Carbon $received_at
 * @property string|null $message_id
 * @property int $rows_count
 */
class ProductExpectedArrivalSnapshot extends Model
{
    protected $fillable = [
        'product_id',
        'calculated_at',
        'received_at',
        'message_id',
        'rows_count',
    ];

    protected function casts(): array
    {
        return [
            'calculated_at' => 'datetime',
            'received_at' => 'datetime',
            'rows_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
    }
}
