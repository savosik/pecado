<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * US-11: Сегмент номенклатуры.
 * Группа товаров из 1С, используемая для расчёта скидок (US-03).
 */
class ProductSegment extends Model
{
    protected $fillable = [
        'uuid',
        'name',
    ];

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_product_segment');
    }
}
