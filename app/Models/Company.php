<?php

namespace App\Models;

use App\Enums\Country;
use App\Enums\Crm\CrmScope;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $user_id
 * @property Country|null $country
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property string|null $registration_number
 * @property string|null $tax_code
 * @property string|null $okpo_code
 * @property string|null $legal_address
 * @property array<array-key, mixed>|null $legal_address_data
 * @property string|null $actual_address
 * @property array<array-key, mixed>|null $actual_address_data
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_default
 * @property string|null $erp_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CompanyBankAccount> $bankAccounts
 * @property-read int|null $bank_accounts_count
 * @property-read \App\Models\ContractorBalance|null $contractorBalance
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CompanyBankAccount> $primaryBankAccount
 * @property-read int|null $primary_bank_account_count
 * @property-read \App\Models\User|null $user
 *
 * @method static \Database\Factories\CompanyFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereActualAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereActualAddressData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereErpId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLegalAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLegalAddressData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereLegalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereOkpoCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereRegistrationNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereTaxCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereTaxId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Company withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Company extends Model
{
    use HasFactory, SoftDeletes;

    // Транзиентный флаг: модель только что обновлена входящим сообщением из 1С
    // (HandleContractor*). Listener PublishContractorToErp и Observer
    // CompanyBankAccountObserver проверяют его и пропускают публикацию обратно
    // в 1С — страховка от петли поверх Company::withoutEvents().
    public bool $fromErp = false;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope);
    }

    protected $fillable = [
        'user_id',
        'country',
        'name',
        'legal_name',
        'tax_id',
        'registration_number',
        'tax_code',
        'okpo_code',
        'legal_address',
        'legal_address_data',
        'actual_address',
        'actual_address_data',
        'phone',
        'email',
        'is_default',
        'erp_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'country' => Country::class,
            'legal_address_data' => 'array',
            'actual_address_data' => 'array',
            'is_default' => 'boolean',
        ];
    }

    // КПП обязателен NOT NULL для составного UNIQUE (tax_id, tax_code, deleted_at).
    // ИП и иностранные компании КПП не имеют — пишем пустую строку.
    protected function taxCode(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ?? '',
        );
    }

    /**
     * The event map for the model.
     *
     * @var array<string, string>
     */
    protected $dispatchesEvents = [
        'created' => \App\Events\CompanyCreated::class,
        'updated' => \App\Events\CompanyUpdated::class,
        'deleted' => \App\Events\CompanyDeleted::class,
    ];

    /**
     * Get the user that owns the company.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the bank accounts for the company.
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    /**
     * Роли людей при этом юрлице: бухгалтер, директор, закупщик.
     *
     * Связь идёт на привязки, а не на людей: один и тот же человек бывает
     * бухгалтером двух юрлиц, и карточка у него одна.
     */
    public function contactLinks(): MorphMany
    {
        return $this->morphMany(ContactLink::class, 'subject');
    }

    /**
     * Get the primary bank account for the company.
     */
    public function primaryBankAccount(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class)->where('is_primary', true);
    }

    /**
     * Get the orders for the company.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Баланс контрагента (US-10).
     */
    public function contractorBalance(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ContractorBalance::class);
    }

    /**
     * Реализации, проведённые на это юрлицо.
     *
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Договоры реестра, подписанные с этим юрлицом.
     *
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Задачи CRM, поставленные по этому контрагенту.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<CrmTask, $this>
     */
    public function crmTasks(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(CrmTask::class, 'related');
    }

    /**
     * Комментарии CRM, оставленные на карточке контрагента.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<CrmComment, $this>
     */
    public function crmComments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(CrmComment::class, 'commentable');
    }

    /**
     * Контрагенты, видимые сотруднику в CRM.
     *
     * Правило то же, что у документов: менеджер видит юрлица только своих партнёров,
     * а контрагент без партнёра (1С прислала юрлицо раньше привязки) доступен лишь
     * тем, кто видит отдел целиком. Иначе карточка юрлица стала бы обходом скоупа.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleInCrm(Builder $query, User $actor): Builder
    {
        return $query->scopedInCrm($actor, CrmScope::DEPARTMENT);
    }

    /**
     * То же с учётом выбранного разреза «только мои / весь отдел».
     *
     * Контрагент без партнёра (1С прислала юрлицо раньше привязки) доступен
     * лишь тому, кто видит отдел: иначе карточка юрлица стала бы обходом скоупа.
     * Но «весь отдел» — это юрлица клиентов отдела плюс непривязанные,
     * а не вообще все компании базы: юрлица лидов и сотрудников сюда не входят.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeScopedInCrm(Builder $query, User $actor, CrmScope $scope): Builder
    {
        $clients = User::query()->inCrmScope($actor, $scope)->select('users.id');

        if (! $actor->can('crm-department.view')) {
            return $query->whereIn('companies.user_id', $clients);
        }

        return $query->where(fn (Builder $inner) => $inner
            ->whereIn('companies.user_id', $clients)
            ->orWhereNull('companies.user_id'));
    }
}
