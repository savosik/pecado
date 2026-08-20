<?php

namespace App\Models;

use App\Enums\ClientContactRole;
use App\Enums\Crm\CrmScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Контактное лицо контрагента в адресной книге партнёра.
 *
 * Адресат правил пульта уведомлений. Правило ссылается на эту карточку, а не на
 * строку адреса: сменился бухгалтер — правится одна запись, и все правила разом
 * начинают писать новому.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property string $full_name
 * @property ClientContactRole $role
 * @property string|null $position
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 * @property bool $is_active
 * @property bool $marketing_consent
 * @property \Illuminate\Support\Carbon|null $marketing_consent_at
 * @property \Illuminate\Support\Carbon|null $unsubscribed_at
 * @property string $unsubscribe_token
 * @property string $source
 * @property string|null $notes
 * @property string|null $erp_uuid
 * @property int|null $created_by_user_id
 */
class ClientContact extends Model
{
    use HasFactory, SoftDeletes;

    /** Контакт завёл менеджер руками. */
    public const SOURCE_MANUAL = 'manual';

    /** Контакт распознан из текстовых полей профиля CRM — черновик, требует подтверждения. */
    public const SOURCE_PROFILE_IMPORT = 'profile_import';

    /** Контакт указал сам клиент в кабинете. */
    public const SOURCE_SELF = 'self';

    /** Контакт приехал из 1С (задел, сейчас не используется). */
    public const SOURCE_ERP = 'erp';

    protected $fillable = [
        'user_id',
        'company_id',
        'full_name',
        'role',
        'position',
        'email',
        'phone',
        'is_primary',
        'is_active',
        'marketing_consent',
        'marketing_consent_at',
        'unsubscribed_at',
        'source',
        'notes',
        'erp_uuid',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => ClientContactRole::class,
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contact): void {
            if (empty($contact->unsubscribe_token)) {
                $contact->unsubscribe_token = Str::random(64);
            }
        });

        // Адрес — ключ доставки: приводим к нижнему регистру так же, как users.email,
        // иначе один и тот же человек попадёт в стоп-лист и в правило разными строками.
        static::saving(function (self $contact): void {
            if (filled($contact->email)) {
                $contact->email = mb_strtolower(trim($contact->email));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Контакт годится для рассылки: активен, с адресом и не отписан.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('email')
            ->whereNull('unsubscribed_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRole(Builder $query, ClientContactRole|string $role): Builder
    {
        return $query->where('role', $role instanceof ClientContactRole ? $role->value : $role);
    }

    /**
     * Контакты, доступные сотруднику: границу задаёт видимость партнёра.
     *
     * Отдельного правила для контактов нет намеренно — адресная книга принадлежит
     * партнёру, и кто видит партнёра, тот видит его контакты.
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeScopedInCrm(Builder $query, User $actor, CrmScope $scope): Builder
    {
        return $query->whereIn(
            'client_contacts.user_id',
            User::query()->inCrmScope($actor, $scope)->select('users.id')
        );
    }

    /**
     * Годится ли контакт как адресат события этого партнёра и контрагента.
     *
     * Проверка принадлежности, а не удобство: контакт «Ромашки» не должен получить
     * письмо про «Одуванчик», даже если правило собрано неаккуратно. Контакт без
     * company_id принадлежит партнёру целиком и годится для любого его юрлица.
     */
    public function belongsToSubject(?int $clientUserId, ?int $companyId): bool
    {
        if ($clientUserId === null || $this->user_id !== $clientUserId) {
            return false;
        }

        return $this->company_id === null || $this->company_id === $companyId;
    }
}
