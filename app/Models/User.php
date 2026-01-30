<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Country;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'patronymic',
        'phone',
        'country',
        'city',
        'is_subscribed',
        'terms_accepted',
        'comment',
        'status',
        'email',
        'password',
        'is_admin',
        'erp_id',
        'region_id',
        'currency_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'is_admin' => 'boolean',
            'country' => Country::class,
            'region_id' => 'integer',
            'currency_id' => 'integer',
        ];
    }

    /**
     * The event map for the model.
     *
     * @var array<string, string>
     */
    protected $dispatchesEvents = [
        'created' => \App\Events\UserCreated::class,
        'updated' => \App\Events\UserUpdated::class,
    ];

    /**
     * Get the companies for the user.
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Get the delivery addresses for the user.
     */
    public function deliveryAddresses(): HasMany
    {
        return $this->hasMany(DeliveryAddress::class);
    }
    /**
     * Get the region that the user belongs to.
     */
    public function region(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Get the currency that the user belongs to.
     */
    public function currency(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
    /**
     * Get the discounts for the user.
     */
    public function discounts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Discount::class, 'discount_user');
    }

    /**
     * Get the favorites for the user.
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Get the wishlist items for the user.
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * Get the products that are favorited by the user.
     */
    public function favoriteProducts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorites');
    }

    /**
     * Get the products in the user's wishlist.
     */
    public function wishlistProducts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlist_items');
    }

    /**
     * Get the carts for the user.
     */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the balances for the user.
     */
    public function balances(): HasMany
    {
        return $this->hasMany(UserBalance::class);
    }
}
