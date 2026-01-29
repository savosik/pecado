<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'base_price',
        'external_id',
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

    /**
     * Get the warehouses with stock for the product.
     */
    public function warehouses(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'product_warehouse')
            ->withPivot('quantity')
            ->withTimestamps();
    }
}
