<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * Get the users for the region.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the primary warehouses for the region.
     */
    public function primaryWarehouses(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'region_warehouse')
            ->wherePivot('type', 'primary')
            ->withTimestamps();
    }

    /**
     * Get the preorder warehouses for the region.
     */
    public function preorderWarehouses(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'region_warehouse')
            ->wherePivot('type', 'preorder')
            ->withTimestamps();
    }
}
