<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'currency_id',
    ];

    /**
     * Get the users for the region.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the currency for the region.
     */
    public function currency(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Currency::class);
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

    /**
     * ID региона по умолчанию.
     * Используется для гостей в каталоге и при автоматическом назначении региона
     * (регистрация, обработка 1С partner.created).
     */
    public static function defaultId(): ?int
    {
        return static::orderBy('id')->value('id');
    }
}
