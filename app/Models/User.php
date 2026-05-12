<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Country;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property bool $must_change_password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $phone
 * @property Country|null $country
 * @property string|null $city
 * @property bool $is_subscribed
 * @property bool $terms_accepted
 * @property string|null $comment
 * @property UserStatus $status
 * @property string|null $erp_id
 * @property string|null $view_token
 * @property int|null $region_id
 * @property int|null $currency_id
 * @property int|null $client_status_id
 * @property int|null $personal_manager_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ApiToken> $apiTokens
 * @property-read int|null $api_tokens_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Cart> $carts
 * @property-read int|null $carts_count
 * @property-read \App\Models\ClientStatus|null $clientStatus
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Company> $companies
 * @property-read int|null $companies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ContractorBalance> $contractorBalances
 * @property-read int|null $contractor_balances_count
 * @property-read \App\Models\Currency|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DeliveryAddress> $deliveryAddresses
 * @property-read int|null $delivery_addresses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $favoriteProducts
 * @property-read int|null $favorite_products_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Favorite> $favorites
 * @property-read int|null $favorites_count
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection<int, \App\Models\Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \App\Models\PersonalManager|null $personalManager
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductExport> $productExports
 * @property-read int|null $product_exports_count
 * @property-read \App\Models\UserQuestionnaire|null $questionnaire
 * @property-read \App\Models\Region|null $region
 * @property-read mixed $resolved_currency
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductReturn> $returns
 * @property-read int|null $returns_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SearchHistory> $searchHistories
 * @property-read int|null $search_histories_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SocialAccount> $socialAccounts
 * @property-read int|null $social_accounts_count
 * @property-read mixed $status_label
 * @property-read mixed $temporary_password
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WishlistItem> $wishlistItems
 * @property-read int|null $wishlist_items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $wishlistProducts
 * @property-read int|null $wishlist_products_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, ?string $guard = null, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereClientStatusId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCurrencyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereErpId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsSubscribed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMustChangePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePersonalManagerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRegionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTermsAccepted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereViewToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, ?string $guard = null)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable;

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = ['status_label'];

    /**
     * Человекочитаемое название статуса.
     */
    protected function statusLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status?->label() ?? '—',
        );
    }

    /**
     * Временный пароль для пользователей, которым требуется смена пароля.
     */
    protected function temporaryPassword(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->email) ? sprintf('%u', crc32($this->email)) : null,
        );
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'country',
        'city',
        'is_subscribed',
        'terms_accepted',
        'comment',
        'status',
        'email',
        'password',
        'must_change_password',
        'erp_id',
        'view_token',
        'region_id',
        'client_status_id',
        'personal_manager_id',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $user) {
            if (empty($user->view_token)) {
                $user->view_token = \Illuminate\Support\Str::random(48);
            }
        });
    }

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
            'must_change_password' => 'boolean',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            'country' => Country::class,
            'status' => UserStatus::class,
            'region_id' => 'integer',
            'client_status_id' => 'integer',
            'personal_manager_id' => 'integer',
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
     * Отправить локализованное уведомление о сбросе пароля.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\Auth\ResetPasswordNotification($token));
    }

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
     * Get the currency for the user (resolved through region).
     *
     * Валюта определяется через регион пользователя.
     * Обратная совместимость: $user->currency по-прежнему возвращает Currency.
     */
    public function currency(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        // Сохраняем как BelongsTo для совместимости с eager-loading и типами.
        // Реальный резолв через region->currency происходит в UserCurrencyResolver.
        return $this->belongsTo(Currency::class);
    }

    /**
     * Accessor: resolve currency through region.
     * $user->resolved_currency returns Currency from region.
     */
    protected function resolvedCurrency(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->region?->currency,
        );
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
     * Балансы контрагентов пользователя (по ИНН).
     */
    public function contractorBalances(): HasMany
    {
        return $this->hasMany(ContractorBalance::class);
    }

    /**
     * Get the returns for the user.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class);
    }

    /**
     * Get the product exports for the user.
     */
    public function productExports(): HasMany
    {
        return $this->hasMany(ProductExport::class);
    }

    /**
     * Get the API tokens for the user.
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * Get the social accounts for the user.
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Get the search history for the user.
     */
    public function searchHistories(): HasMany
    {
        return $this->hasMany(SearchHistory::class);
    }

    /**
     * Get the questionnaire for the user.
     */
    public function questionnaire(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserQuestionnaire::class);
    }

    /**
     * Get the client status for the user.
     */
    public function clientStatus(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ClientStatus::class);
    }

    /**
     * Get the personal manager assigned to the user.
     */
    public function personalManager(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PersonalManager::class);
    }
}
