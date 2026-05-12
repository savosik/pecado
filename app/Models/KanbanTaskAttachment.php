<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $kanban_task_id
 * @property string $original_name
 * @property string $path
 * @property string|null $mime_type
 * @property int $size
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $url
 * @property-read \App\Models\KanbanTask $task
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment whereKanbanTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment whereOriginalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KanbanTaskAttachment whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class KanbanTaskAttachment extends Model
{
    protected $fillable = [
        'kanban_task_id',
        'original_name',
        'path',
        'mime_type',
        'size',
    ];

    protected $appends = ['url'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(KanbanTask::class, 'kanban_task_id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
