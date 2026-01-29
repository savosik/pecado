<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'external_id',
    ];

    /**
     * Get the products with stock in the warehouse.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_warehouse')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Get the regions where this warehouse is primary.
     */
    public function primaryRegions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'region_warehouse')
            ->wherePivot('type', 'primary')
            ->withTimestamps();
    }

    /**
     * Get the regions where this warehouse is for preorder.
     */
    public function preorderRegions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'region_warehouse')
            ->wherePivot('type', 'preorder')
            ->withTimestamps();
    }
}
