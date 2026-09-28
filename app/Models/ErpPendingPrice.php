<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Цена из price.updated, обогнавшая product.created своего товара.
 * Живёт до создания карточки, см. App\Services\Erp\Support\PendingPrices.
 *
 * @property int $id
 * @property string $product_uuid
 * @property string $price
 * @property string|null $message_id
 */
class ErpPendingPrice extends Model
{
    protected $fillable = ['product_uuid', 'price', 'message_id'];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
