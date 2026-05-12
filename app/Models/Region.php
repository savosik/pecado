<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int|null $currency_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Currency|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Warehouse> $preorderWarehouses
 * @property-read int|null $preorder_warehouses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Warehouse> $primaryWarehouses
 * @property-read int|null $primary_warehouses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 *
 * @method static \Database\Factories\RegionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region whereCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Region whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
