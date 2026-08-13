<?php

namespace App\Models;

use App\Enums\Crm\CrmScope;
use App\Models\Concerns\HasCrmAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;

/**
 * Лид — потенциальный клиент до появления партнёра в 1С.
 *
 * Живёт вне `users` намеренно: `users` — зона 1С, а лид это «имя и телефон
 * на бумажке». Требовать от него учётку значило бы не дать завести его вовсе.
 *
 * Конверсия в клиента — привязка `converted_user_id` к партнёру, которого
 * создала 1С, а не создание пользователя из CRM.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $messenger
 * @property int|null $manager_id
 * @property int|null $stage_id
 * @property float|null $qualified_amount
 * @property int|null $converted_user_id
 * @property Carbon|null $stage_changed_at
 */
class CrmLead extends Model implements HasMedia
{
    use HasCrmAttachments, HasFactory, SoftDeletes;

    /**
     * Со скольких дней без движения лид считается залежавшимся.
     *
     * Одно число на весь раздел: по нему краснеет бейдж на карточке, фильтруется
     * таблица и отбирает работу команда напоминаний. Разъехавшись, они показывали
     * бы менеджеру разные ответы на один вопрос «что у меня стоит».
     */
    public const STALE_DAYS = 14;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'messenger',
        'company_name',
        'source',
        'manager_id',
        'stage_id',
        'qualified_amount',
        'currency_code',
        'expected_close_at',
        'decision_maker',
        'interests',
        'notes',
        'lost_reason',
        'converted_user_id',
        'stage_changed_at',
        'stale_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'qualified_amount' => 'decimal:2',
            'expected_close_at' => 'date',
            'stage_changed_at' => 'datetime',
            'stale_reminded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PersonalManager, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'manager_id');
    }

    /**
     * @return BelongsTo<CrmLeadStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmLeadStage::class, 'stage_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(CrmLeadStageTransition::class, 'lead_id');
    }

    public function crmComments(): MorphMany
    {
        return $this->morphMany(CrmComment::class, 'commentable');
    }

    public function crmTasks(): MorphMany
    {
        return $this->morphMany(CrmTask::class, 'related');
    }

    /**
     * Лиды, видимые сотруднику.
     *
     * Через `users` не проходит — там партнёры. Но правило то же самое:
     * без права на отдел видно только своё, и разрез «только мои» сужает
     * уже видимое.
     *
     * Ничей лид (без менеджера) виден всем, кому доступен раздел: в отличие
     * от партнёра без менеджера, которого мы намеренно прячем, неразобранный
     * лид обязан кому-то попасться на глаза — иначе он просто пропадёт.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $actor, CrmScope $scope = CrmScope::DEPARTMENT): Builder
    {
        $managerId = $actor->managerProfile?->id;

        if ($actor->can('crm-department.view') && ! $scope->isMine()) {
            return $query;
        }

        if ($managerId === null) {
            // Сотрудник без карточки менеджера своих лидов не имеет —
            // остаются только неразобранные.
            return $query->whereNull('manager_id');
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where('manager_id', $managerId)
            ->orWhereNull('manager_id'));
    }

    /**
     * Лиды, застрявшие на стадии дольше порога.
     *
     * Терминальные стадии исключены: выигранный и проигранный лид стоит на месте
     * по определению, и напоминать по нему не о чем.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStagnant(Builder $query, ?int $days = null): Builder
    {
        return $query
            ->whereNotNull('stage_changed_at')
            ->where('stage_changed_at', '<=', Carbon::now()->subDays($days ?? self::STALE_DAYS))
            ->whereNull('converted_user_id')
            ->whereHas('stage', fn (Builder $stage) => $stage
                ->where('is_won', false)
                ->where('is_lost', false));
    }

    /**
     * Сколько дней лид стоит на текущей стадии.
     */
    public function daysOnStage(): ?int
    {
        if ($this->stage_changed_at === null) {
            return null;
        }

        return (int) $this->stage_changed_at->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }

    /**
     * Первый заполненный контакт — для списка и карточки.
     */
    public function primaryContact(): ?string
    {
        return $this->phone ?: ($this->email ?: $this->messenger);
    }
}
