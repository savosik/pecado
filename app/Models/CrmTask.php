<?php

namespace App\Models;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\Concerns\HasCrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property-read Model|null $related
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CrmComment> $comments
 * @property-read int|null $attachments_count
 */
class CrmTask extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\CrmTaskFactory> */
    use HasCrmAttachments, HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'author_id',
        'assignee_id',
        'client_user_id',
        'related_type',
        'related_id',
        'status',
        'priority',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'done_at' => 'datetime',
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
     * их забыть — и задача молча выпала бы из ленты клиента или осталась «выполненной»
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

            // Задачу переоткрыли — факт выполнения больше не факт.
            if ($task->status !== TaskStatus::DONE && $task->done_at !== null) {
                $task->done_at = null;
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
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

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->status->isOpen()
            && $this->due_at->isPast();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assignee_id', $userId);
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
     * Лента клиента: задачи, сводящиеся к этому клиенту.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClient(Builder $query, int $clientUserId): Builder
    {
        return $query->where('client_user_id', $clientUserId);
    }
}
