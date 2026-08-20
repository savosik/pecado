<?php

namespace App\Models;

use App\Enums\Crm\CrmScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Правило маршрутизации уведомлений.
 *
 * Разбор идёт как в почтовых фильтрах: правила упорядочены приоритетом,
 * срабатывают все совпавшие, stop_processing прерывает дальнейший разбор.
 *
 * @property int $id
 * @property string $name
 * @property string $event_key
 * @property string $scope_type
 * @property array|null $conditions
 * @property int $priority
 * @property bool $stop_processing
 * @property bool $is_active
 * @property bool $is_system
 * @property string|null $system_key
 * @property string $channel
 */
class NotificationRule extends Model
{
    use HasFactory, SoftDeletes;

    /** Правило действует на всех партнёров. */
    public const SCOPE_GLOBAL = 'global';

    /** Правило конкретного партнёра. */
    public const SCOPE_USER = 'user';

    /** Правило конкретного юрлица. */
    public const SCOPE_COMPANY = 'company';

    /** Правило для всех клиентов персонального менеджера. */
    public const SCOPE_MANAGER = 'manager';

    /**
     * Приоритет, с которого начинаются системные правила.
     *
     * Пользовательские живут ниже (100 по умолчанию) и потому разбираются
     * раньше — менеджер может перебить системное поведение своим правилом.
     */
    public const SYSTEM_PRIORITY_FLOOR = 400;

    protected $fillable = [
        'name',
        'description',
        'event_key',
        'scope_type',
        'scope_user_id',
        'scope_company_id',
        'scope_manager_id',
        'conditions',
        'priority',
        'stop_processing',
        'is_active',
        'is_system',
        'system_key',
        'preset_key',
        'channel',
        'template_key',
        'subject_override',
        'attach_documents',
        'throttle_seconds',
        'digest',
        'quiet_hours',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'quiet_hours' => 'array',
            'stop_processing' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'attach_documents' => 'boolean',
            'last_matched_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRuleRecipient::class);
    }

    public function scopeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scope_user_id');
    }

    public function scopeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'scope_company_id');
    }

    public function scopeManager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'scope_manager_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Отметить срабатывание — по этим полям в списке видно мёртвые правила.
     */
    public function registerMatch(): void
    {
        $this->forceFill([
            'last_matched_at' => now(),
            'matched_count' => $this->matched_count + 1,
        ])->saveQuietly();
    }

    /**
     * Правило-политика: действует на всю базу, получатель обычно задан ролью.
     *
     * Основной способ настройки — одно такое правило покрывает всех партнёров,
     * тогда как поштучные правила нужны только под исключения.
     */
    public function isPolicy(): bool
    {
        return in_array($this->scope_type, [self::SCOPE_GLOBAL, self::SCOPE_MANAGER], true);
    }

    /**
     * Правила, доступные сотруднику.
     *
     * Глобальные и системные видны всем, у кого есть доступ в раздел, — иначе
     * менеджер не понял бы, почему письмо ушло. Правила конкретных партнёров
     * ограничены его скоупом.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleInCrm(Builder $query, User $actor): Builder
    {
        if ($actor->can('crm-notifications-all.view')) {
            return $query;
        }

        $clients = User::query()->inCrmScope($actor, CrmScope::DEPARTMENT)->select('users.id');

        return $query->where(function (Builder $q) use ($clients) {
            $q->whereIn('scope_type', [self::SCOPE_GLOBAL, self::SCOPE_MANAGER])
                ->orWhereIn('scope_user_id', clone $clients)
                ->orWhereIn('scope_company_id', Company::query()
                    ->whereIn('user_id', clone $clients)
                    ->select('companies.id'));
        });
    }

    /**
     * Может ли сотрудник править это правило.
     *
     * Системные и глобальные — только под crm-notifications-all.edit: массовая
     * рассылка по всей базе не должна быть в руках одного менеджера.
     */
    public function isEditableBy(User $actor): bool
    {
        if ($this->is_system || $this->isPolicy()) {
            return $actor->can('crm-notifications-all.edit');
        }

        return $actor->can('crm-notifications.edit');
    }
}
