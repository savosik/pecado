<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
