<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
