<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Country;
use App\Enums\Crm\CrmScope;
use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\Concerns\HasCrmAttachments;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Tags\HasTags;

/**
 * @property int $id
 * @property string|null $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property bool $stock_buffer_enabled Страховой запас: показывать заниженные остатки по рисковым товарам (buf-02)
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
 * @property UserKind $user_kind
 * @property string|null $erp_id
 * @property string|null $erp_name
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
 * @property-read \App\Models\CrmClientProfile|null $crmProfile
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CrmTask> $crmTasks
 * @property-read int|null $active_tasks_count
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
 * @property-read mixed $display_name
 * @property-read mixed $personal_name_if_differs
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
    // InteractsWithMedia приходит внутри HasCrmAttachments: подключать его ещё и напрямую
    // нельзя — registerMediaCollections() из двух трейтов даёт коллизию методов.
    use HasApiTokens, HasCrmAttachments, HasFactory, HasRoles, HasTags, Notifiable;
    use \NotificationChannels\WebPush\HasPushSubscriptions;

    /**
     * Тип тегов «интересы клиента» в CRM.
     *
     * Отдельный тип, а не своя таблица: `spatie/laravel-tags` уже стоит, а тип
     * не даёт интересам клиента смешаться с товарными тегами в общем справочнике.
     */
    public const INTEREST_TAG_TYPE = 'crm-interest';

    /**
     * Префикс прав домена /crm/.
     *
     * По нему hasAdminAccess()/hasCrmAccess() отличают CRM-права от админских,
     * поэтому имена CRM-ресурсов в RolesAndPermissionsSeeder обязаны его нести
     * (страхует PermissionNamingTest).
     */
    public const CRM_PERMISSION_PREFIX = 'crm-';

    /**
     * Префикс прав домена /wms/ (кабинет склада).
     */
    public const WMS_PERMISSION_PREFIX = 'wms-';

    /**
     * Префиксы прав, принадлежащих отдельным панелям.
     *
     * Такие права НЕ дают входа в /admin: сотрудник только с ними работает
     * исключительно в своей панели. Каждый новый домен обязан попасть в этот
     * список, иначе его сотрудники молча получат доступ в админку.
     */
    public const PANEL_PERMISSION_PREFIXES = [
        self::CRM_PERMISSION_PREFIX,
        self::WMS_PERMISSION_PREFIX,
    ];

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
     * Имя, под которым клиента знает отдел продаж.
     *
     * Менеджеры сличают отчёты сайта и 1С по рабочему наименованию карточки
     * партнёра, а `name` клиент правит в кабинете как хочет. Поэтому везде, где
     * страницу читает сотрудник (CRM, админка), подписью идёт `erp_name`, и
     * только у партнёров без карточки в 1С остаётся то имя, что ввёл сам клиент.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->erp_name) ? $this->erp_name : $this->name,
        );
    }

    /**
     * Личное имя — только когда оно расходится с рабочим наименованием.
     *
     * Показывать «Иванов (Иванов)» незачем: второй строкой в CRM имя выводится
     * ровно тогда, когда клиент назвал себя иначе, чем записано в 1С.
     */
    protected function personalNameIfDiffers(): Attribute
    {
        return Attribute::make(
            get: fn () => filled($this->erp_name) && trim((string) $this->erp_name) !== trim((string) $this->name)
                ? $this->name
                : null,
        );
    }

    /**
     * E-mail всегда храним в нижнем регистре (с trim).
     *
     * Иначе temporary_password (crc32 от email) расходится с паролем, который
     * 1С генерирует от email в нижнем регистре, и партнёр не может войти по
     * выданному временному паролю.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value !== null ? mb_strtolower(trim($value)) : $value,
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
        'default_delivery_method',
        'is_subscribed',
        'terms_accepted',
        'comment',
        'status',
        'user_kind',
        'email',
        'password',
        'must_change_password',
        'erp_id',
        'erp_name',
        'view_token',
        'region_id',
        'client_status_id',
        'personal_manager_id',
    ];

    /**
     * Значения по умолчанию для новых моделей.
     *
     * Дефолт колонки задан и в БД, но модель после create() его не перечитывает,
     * а user_kind проверяют сразу же (например при выдаче роли в админке).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'user_kind' => UserKind::CLIENT->value,
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
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_subscribed' => 'boolean',
            'terms_accepted' => 'boolean',
            // Не в $fillable намеренно: меняется только CRM-экшеном
            // (ClientLifecycleService::changeStockBuffer), не массовым заполнением.
            'stock_buffer_enabled' => 'boolean',
            'country' => Country::class,
            'default_delivery_method' => \App\Enums\DeliveryMethod::class,
            'status' => UserStatus::class,
            'user_kind' => UserKind::class,
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
     *
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Договоры реестра, заведённые на партнёра (по любому из его юрлиц).
     *
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
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
     * Реализации (отгрузки) клиента.
     *
     * Нужна для поиска клиента по номеру документа в списке CRM. Расчёт выручки
     * через неё вести нельзя — он живёт в ShipmentAnalyticsService и только там.
     *
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Подписки пользователя на изменения сущностей разделов кабинета
     * (email/telegram). См. App\Models\EntitySubscription.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(EntitySubscription::class);
    }

    /**
     * Адресная книга партнёра: все люди, заведённые под него.
     *
     * Сюда попадают и контакты его юрлиц: у карточки человека партнёр один,
     * а привязок к юрлицам может быть несколько.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'client_user_id');
    }

    /**
     * Личные пресеты фильтров отчёта продаж CRM.
     */
    public function crmAnalyticsFilterPresets(): HasMany
    {
        return $this->hasMany(CrmAnalyticsFilterPreset::class);
    }

    /**
     * Личные отборы списка клиентов CRM.
     *
     * @return HasMany<CrmClientFilterPreset, $this>
     */
    public function crmClientFilterPresets(): HasMany
    {
        return $this->hasMany(CrmClientFilterPreset::class);
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
     * Профиль клиента в CRM: наблюдения менеджера, которых нет в 1С.
     *
     * Может отсутствовать — карточка клиента открывается и без профиля,
     * запись создаётся в ClientProfileService при первом сохранении.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<CrmClientProfile, $this>
     */
    public function crmProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CrmClientProfile::class);
    }

    /**
     * Задачи CRM, сводящиеся к этому клиенту.
     *
     * Связь по денормализованному client_user_id, а не по полиморфной привязке:
     * задача по заказу клиента — это тоже задача по клиенту.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CrmTask, $this>
     */
    public function crmTasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CrmTask::class, 'client_user_id');
    }

    /**
     * Комментарии CRM, сводящиеся к этому клиенту.
     *
     * Та же денормализация, что у задач: комментарий к заказу клиента — это
     * комментарий по клиенту. Нужна для поиска по тексту переписки в списке.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CrmComment, $this>
     */
    public function crmComments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CrmComment::class, 'client_user_id');
    }

    /**
     * Звонки CRM по этому клиенту.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CrmCall, $this>
     */
    public function crmCalls(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CrmCall::class, 'client_user_id');
    }

    /**
     * Письма CRM, отправленные по этому клиенту.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<CrmEmail, $this>
     */
    public function crmEmails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CrmEmail::class, 'client_user_id');
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

    /**
     * Карточка персонального менеджера, если этот пользователь — сотрудник отдела продаж.
     *
     * Обратная сторона personalManager(): там пользователь выступает клиентом,
     * здесь — самим менеджером.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<PersonalManager, $this>
     */
    public function managerProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PersonalManager::class);
    }

    /**
     * Сотрудник — любой пользователь с ролью.
     *
     * Витрина по этому признаку показывает цены и корзину независимо от status,
     * поэтому проверка намеренно широкая: доступа в панели она не даёт.
     */
    public function isStaff(): bool
    {
        return $this->loadMissing('roles')->roles->isNotEmpty();
    }

    /**
     * Доступ в /admin — super-admin или хотя бы одно НЕ-CRM право.
     *
     * Сотрудник, у которого есть только права с префиксом CRM_PERMISSION_PREFIX,
     * работает исключительно в /crm и в админку не попадает.
     */
    public function hasAdminAccess(): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }

        return $this->getAllPermissions()->contains(
            fn ($permission) => ! self::isPanelPermission($permission->name)
        );
    }

    /**
     * Доступ в /crm — super-admin или хотя бы одно CRM-право.
     */
    public function hasCrmAccess(): bool
    {
        return $this->hasPanelAccess(self::CRM_PERMISSION_PREFIX);
    }

    /**
     * Доступ в /wms — super-admin или хотя бы одно складское право.
     */
    public function hasWmsAccess(): bool
    {
        return $this->hasPanelAccess(self::WMS_PERMISSION_PREFIX);
    }

    /**
     * Есть ли у пользователя хотя бы одно право с указанным панельным префиксом.
     */
    private function hasPanelAccess(string $prefix): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }

        return $this->getAllPermissions()->contains(
            fn ($permission) => str_starts_with($permission->name, $prefix)
        );
    }

    /**
     * Принадлежит ли право одной из панелей (/crm, /wms), а не админке.
     */
    private static function isPanelPermission(string $permissionName): bool
    {
        foreach (self::PANEL_PERMISSION_PREFIXES as $prefix) {
            if (str_starts_with($permissionName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Только клиентские учётки — без сотрудников и служебных аккаунтов.
     *
     * 1С шлёт партнёрами всех подряд и проставляет им personal_manager_id,
     * поэтому «есть менеджер» клиентом больше не считается: тип аккаунта
     * задаётся явно в user_kind.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeClients(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('users.user_kind', UserKind::CLIENT->value);
    }

    /**
     * Клиенты, видимые пользователю в CRM.
     *
     * Кто видит отдел (crm-department.view) — всех клиентов отдела,
     * менеджер — только закреплённых за его карточкой, менеджер без карточки — никого.
     *
     * Порядок веток важен: право на весь отдел проверяется до managerProfile,
     * иначе РОП без карточки менеджера увидел бы пустой список.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeVisibleInCrm(\Illuminate\Database\Eloquent\Builder $query, self $actor): \Illuminate\Database\Eloquent\Builder
    {
        // Фильтр по типу — общий для обеих веток: сотрудник, закреплённый за
        // менеджером в 1С, не должен попадать в CRM ни к РОПу, ни к менеджеру.
        $query->clients();

        if ($actor->can('crm-department.view')) {
            // Клиент без менеджера — лид, он живёт в админке, а не в CRM отдела.
            return $query->whereNotNull('personal_manager_id');
        }

        $managerId = $actor->managerProfile?->id;

        if ($managerId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('personal_manager_id', $managerId);
    }

    /**
     * Сузить видимых клиентов до выбранного разреза.
     *
     * Надстройка над {@see scopeVisibleInCrm()}, а не замена: право задаёт
     * границу возможного, разрез — фокус внутри неё. Поэтому применяется
     * поверх и только в списках; в show-роутах границу держит право.
     *
     * Разрез «мои» у сотрудника без карточки менеджера ничего не сужает:
     * {@see CrmScope::resolve()} до этого места такой разрез не пропускает.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeInCrmScope(\Illuminate\Database\Eloquent\Builder $query, self $actor, CrmScope $scope): \Illuminate\Database\Eloquent\Builder
    {
        $query->visibleInCrm($actor);

        $managerId = $actor->managerProfile?->id;

        if ($scope->isMine() && $managerId !== null) {
            $query->where('personal_manager_id', $managerId);
        }

        return $query;
    }
}
