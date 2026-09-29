<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Цена из price.updated с будущей датой вступления в силу (v16.13.0, топик №15).
 * Живёт до своей даты, см. App\Services\Erp\Support\ScheduledPrices.
 *
 * @property int $id
 * @property string $product_uuid
 * @property string $document_uuid
 * @property string|null $document_number
 * @property string $price
 * @property \Illuminate\Support\Carbon $effective_from
 * @property string|null $message_id
 */
class ErpScheduledPrice extends Model
{
    protected $fillable = ['product_uuid', 'document_uuid', 'document_number', 'price', 'effective_from', 'message_id'];

    protected $casts = [
        'price' => 'decimal:2',
        'effective_from' => 'datetime',
    ];
}
