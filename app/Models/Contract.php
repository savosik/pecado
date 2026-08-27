<?php

namespace App\Models;

use App\Enums\Crm\ContractForm;
use App\Enums\Crm\ContractPaymentTerms;
use App\Enums\Crm\ContractStatus;
use App\Enums\Crm\CrmScope;
use App\Models\Concerns\HasCrmAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

/**
 * Договор с партнёром из реестра отдела продаж.
 *
 * Сущность сайта: заводит и ведёт менеджер, 1С о ней не знает. Сканы лежат
 * в media (коллекция crm-attachments), задачи и комментарии — полиморфно через
 * CrmEntityMap, как у контрагента.
 *
 * Партнёр (`user_id`) денормализован из контрагента и держится в актуальном
 * состоянии при сохранении: по нему считается скоуп менеджера, и без него
 * договор контрагента, которого 1С перепривязала к другому партнёру, повис бы.
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $user_id
 * @property int|null $company_id
 * @property string|null $counterparty_name
 * @property string $number
 * @property \Illuminate\Support\Carbon|null $date
 * @property \Illuminate\Support\Carbon|null $signed_at
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property ContractStatus $status
 * @property ContractPaymentTerms|null $payment_terms
 * @property ContractForm|null $form
 * @property int|null $responsible_manager_id
 * @property bool $is_visible_in_cabinet
 * @property string|null $comment
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property-read ContractCategory $category
 * @property-read User|null $user
 * @property-read Company|null $company
 * @property-read PersonalManager|null $responsibleManager
 * @property-read User|null $createdBy
 * @property-read string $counterparty_label
 * @property-read bool $is_expired
 *
 * @method static \Database\Factories\ContractFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class Contract extends Model implements HasMedia
{
    use HasCrmAttachments;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'user_id',
        'company_id',
        'counterparty_name',
        'number',
        'date',
        'signed_at',
        'valid_from',
        'valid_until',
        'status',
        'payment_terms',
        'form',
        'responsible_manager_id',
        'is_visible_in_cabinet',
        'comment',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'signed_at' => 'date',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'status' => ContractStatus::class,
            'payment_terms' => ContractPaymentTerms::class,
            'form' => ContractForm::class,
            'is_visible_in_cabinet' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Партнёр берётся с контрагента, если не задан явно: менеджер выбирает
        // юрлицо, а чей это партнёр — знает само юрлицо. Снимок названия нужен
        // на случай, когда юрлицо позже удалят или переименуют в 1С.
        static::saving(function (self $contract): void {
            if ($contract->company_id !== null) {
                $company = $contract->company ?? Company::query()->withoutGlobalScopes()->find($contract->company_id);

                if ($company instanceof Company) {
                    if ($contract->user_id === null && $company->user_id !== null) {
                        $contract->user_id = $company->user_id;
                    }

                    if (blank($contract->counterparty_name)) {
                        $contract->counterparty_name = $company->name ?: $company->legal_name;
                    }
                }
            }

            $contract->number = trim((string) $contract->number);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContractCategory::class, 'category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function responsibleManager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'responsible_manager_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return MorphMany<CrmTask, $this>
     */
    public function crmTasks(): MorphMany
    {
        return $this->morphMany(CrmTask::class, 'related');
    }

    /**
     * @return MorphMany<CrmComment, $this>
     */
    public function crmComments(): MorphMany
    {
        return $this->morphMany(CrmComment::class, 'commentable');
    }

    /**
     * Договоры, которые «закрывают» контрагента во вкладке «Без договора».
     *
     * @param  Builder<self>  $query
     */
    public function scopeInForce(Builder $query): void
    {
        $query->where('status', '<>', ContractStatus::TERMINATED->value);
    }

    /**
     * Договоры, видимые сотруднику. Граница — партнёр: менеджер видит договоры
     * своих партнёров. Договор без партнёра (иностранный поставщик, контрагент
     * без привязки) — только тем, кто видит отдел, иначе он всплывал бы у каждого.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleInCrm(Builder $query, User $actor): void
    {
        $query->scopedInCrm($actor, CrmScope::DEPARTMENT);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeScopedInCrm(Builder $query, User $actor, CrmScope $scope): void
    {
        $clients = User::query()->inCrmScope($actor, $scope)->select('users.id');

        if (! $actor->can('crm-department.view')) {
            $query->whereIn('contracts.user_id', $clients);

            return;
        }

        $query->where(fn (Builder $inner) => $inner
            ->whereIn('contracts.user_id', $clients)
            ->orWhereNull('contracts.user_id'));
    }

    /**
     * Договоры партнёра в кабинете: по его юрлицам (ось та же, что у печатных
     * форм — 1С перепривязывает юрлица, и снимок user_id устаревает) плюс
     * договоры без юрлица, заведённые на самого партнёра.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $partner): void
    {
        $companies = Company::query()->withoutGlobalScopes()
            ->where('user_id', $partner->getKey())
            ->select('id');

        $query
            ->where('is_visible_in_cabinet', true)
            ->where(fn (Builder $inner) => $inner
                ->whereIn('contracts.company_id', $companies)
                ->orWhere(fn (Builder $own) => $own
                    ->whereNull('contracts.company_id')
                    ->where('contracts.user_id', $partner->getKey())));
    }

    /**
     * Название второй стороны: юрлицо из базы, иначе снимок текстом.
     */
    public function getCounterpartyLabelAttribute(): string
    {
        if ($this->relationLoaded('company') && $this->company instanceof Company) {
            return (string) ($this->company->name ?: $this->company->legal_name ?: $this->counterparty_name);
        }

        return (string) ($this->counterparty_name ?: ($this->company->name ?? 'Контрагент не указан'));
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }
}
