<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $kanban_task_id
 * @property int|null $parent_id
 * @property string $content
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read KanbanComment|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, KanbanComment> $replies
 * @property-read int|null $replies_count
 * @property-read \App\Models\KanbanTask $task
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment whereKanbanTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanComment whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class KanbanComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'kanban_task_id',
        'parent_id',
        'content',
    ];

    protected $with = ['replies'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(KanbanTask::class, 'kanban_task_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(KanbanComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(KanbanComment::class, 'parent_id');
    }
}
