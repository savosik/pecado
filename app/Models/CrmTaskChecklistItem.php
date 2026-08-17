<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Пункт чек-листа задачи CRM.
 *
 * Плоский todo без исполнителя и срока: кто и когда отметил — фиксируется,
 * чтобы в командной задаче было видно, чья галочка.
 *
 * @property int $id
 * @property int $task_id
 * @property string $title
 * @property int $position
 * @property bool $is_done
 * @property int|null $done_by_id
 * @property \Illuminate\Support\Carbon|null $done_at
 * @property-read CrmTask $task
 * @property-read User|null $doneBy
 */
class CrmTaskChecklistItem extends Model
{
    protected $fillable = [
        'title',
        'position',
        'is_done',
        'done_by_id',
        'done_at',
    ];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class, 'task_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_id');
    }

    /**
     * Отметить или снять пункт, зафиксировав автора отметки.
     */
    public function markDone(bool $done, ?int $userId): void
    {
        $this->is_done = $done;
        $this->done_by_id = $done ? $userId : null;
        $this->done_at = $done ? now() : null;
    }
}
