<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка ожидаемого поступления товара на склад сайта (v16.16.0).
 *
 * Строки пишет только обработчик `product.expected_arrivals.updated`: каждый
 * снимок по товару заменяет их целиком. Данные служебные — видят сотрудники
 * в CRM; в витрину, кабинет и клиентский API не выводятся (решение заказчика
 * 01.10.2026). Читать для показа — через {@see \App\Services\Stock\ExpectedArrivals}.
 *
 * @property int $id
 * @property int $product_id
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property string $quantity
 * @property string $source
 */
class ProductExpectedArrival extends Model
{
    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'expected_date',
        'quantity',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
            'quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
