<?php

namespace App\Models;

use App\Enums\Crm\TaskOutcome;
use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\Concerns\HasCrmAttachments;
use App\Models\Concerns\RecordsCrmSource;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

/**
 * Задача менеджера: себе или коллеге, с дедлайном и необязательной привязкой к сущности.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property int $author_id
 * @property int $assignee_id
 * @property int|null $client_user_id
 * @property int|null $follow_up_of_id
 * @property string|null $related_type
 * @property int|null $related_id
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property \Illuminate\Support\Carbon|null $done_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read User $author
 * @property-read User $assignee
 * @property-read User|null $client
 * @property-read CrmTask|null $followUpOf
 * @property-read Model|null $related
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CrmComment> $comments
 * @property-read int|null $attachments_count
 */
class CrmTask extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\CrmTaskFactory> */
    use HasCrmAttachments, HasFactory, RecordsCrmSource, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'author_id',
        'assignee_id',
        'client_user_id',
        'follow_up_of_id',
        'related_type',
        'related_id',
        'status',
        'priority',
        'due_at',
        'estimate_minutes',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'outcome' => TaskOutcome::class,
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'estimate_minutes' => 'integer',
            'postponed_count' => 'integer',
        ];
    }

    /**
     * Значения по умолчанию — те же, что у колонок в БД.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
    ];

    /**
     * Инварианты задачи держим в модели, а не в контроллере.
     *
     * Задача создаётся из интерфейса, из врезки в карточке заказа и (позже) от ИИ-агента.
     * Если бы client_user_id и done_at заполнял вызывающий код, каждый новый путь мог бы
     * их забыть — и задача молча выпала бы из ленты партнёра или осталась «выполненной»
     * без даты выполнения.
     */
    protected static function booted(): void
    {
        static::saving(function (self $task) {
            // related_type проверяем до обращения к связи: иначе каждое сохранение
            // задачи без привязки лезло бы в morphTo за заведомым null.
            if ($task->client_user_id === null && $task->related_type !== null && $task->related instanceof Model) {
                $task->client_user_id = CrmEntityMap::clientIdFor($task->related);
            }

            if ($task->status === TaskStatus::DONE && $task->done_at === null) {
                $task->done_at = now();
            }

            // Задачу переоткрыли — факт выполнения больше не факт, исход тоже.
            if ($task->status !== TaskStatus::DONE && $task->done_at !== null) {
                $task->done_at = null;
            }

            if ($task->status !== TaskStatus::DONE && $task->outcome !== null) {
                $task->outcome = null;
            }
        });
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Соисполнители — работают над задачей вместе с ответственным.
     *
     * Ответственный (`assignee_id`) в pivot не дублируется: у задачи всегда ровно
     * один человек, отвечающий за срок, — на нём каунтеры и покрытие партнёров.
     *
     * @return BelongsToMany<User, $this>
     */
    public function coAssignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'crm_task_assignees', 'task_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Контролёры — наблюдают («личный контроль»), но не исполняют.
     *
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'crm_task_watchers', 'task_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Участвует ли пользователь в исполнении: ответственный или соисполнитель.
     */
    public function isAssigneeOf(int $userId): bool
    {
        if ((int) $this->assignee_id === $userId) {
            return true;
        }

        // Через загруженную коллекцию, когда она есть: политика зовётся на каждую
        // строку списка, и запрос на задачу превратился бы в N+1.
        if ($this->relationLoaded('coAssignees')) {
            return $this->coAssignees->contains(fn (User $user): bool => (int) $user->getKey() === $userId);
        }

        return $this->coAssignees()->whereKey($userId)->exists();
    }

    public function isWatchedBy(int $userId): bool
    {
        if ($this->relationLoaded('watchers')) {
            return $this->watchers->contains(fn (User $user): bool => (int) $user->getKey() === $userId);
        }

        return $this->watchers()->whereKey($userId)->exists();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    /**
     * Задача, при закрытии которой поставлена эта.
     */
    public function followUpOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'follow_up_of_id');
    }

    /**
     * Комментарии к самой задаче — обсуждение по ходу работы.
     *
     * @return MorphMany<CrmComment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(CrmComment::class, 'commentable');
    }

    /**
     * Чек-лист: плоские todo-пункты внутри задачи, в заданном порядке.
     *
     * @return HasMany<CrmTaskChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(CrmTaskChecklistItem::class, 'task_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->status->isOpen()
            && $this->due_at->isPast();
    }

    /**
     * Задачи, где пользователь исполняет: ответственный или соисполнитель.
     *
     * «Мне» для соисполнителя значит то же, что для ответственного, — задача
     * должна быть в его списке, иначе соисполнение теряется.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('assignee_id', $userId)
            ->orWhereHas('coAssignees', fn (Builder $users) => $users->whereKey($userId)));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAuthoredBy(Builder $query, int $userId): Builder
    {
        return $query->where('author_id', $userId);
    }

    /**
     * Срок вышел, а задача всё ещё требует действий.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', TaskStatus::activeValues())
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    /**
     * Срок сегодня — то, что показывает виджет «на сегодня».
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->whereIn('status', TaskStatus::activeValues())
            ->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    /**
     * Лента партнёра: задачи, сводящиеся к этому партнёру.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClient(Builder $query, int $clientUserId): Builder
    {
        return $query->where('client_user_id', $clientUserId);
    }
}
