<?php

namespace App\Models;

use App\Models\Scopes\DeliveryAddressScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $address
 * @property array<array-key, mixed>|null $address_data
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @property-read \App\Models\User $user
 *
 * @method static \Database\Factories\DeliveryAddressFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress whereAddressData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeliveryAddress whereUserId($value)
 *
 * @mixin \Eloquent
 */
class DeliveryAddress extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'address',
        'address_data',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address_data' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new DeliveryAddressScope);
    }

    /**
     * Get the user that owns the delivery address.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the orders for the delivery address.
     */
    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }
}
