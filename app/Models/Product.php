<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'base_price',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
    ];
    /**
     * Get the discounts for the product.
     */
    public function discounts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Discount::class, 'discount_product');
    }
}
