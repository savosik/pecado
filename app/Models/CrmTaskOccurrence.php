<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Порождённое вхождение автоповторяемой задачи.
 *
 * Существует ради уникального ключа `(recurrence_id, occurrence_date)`:
 * повторный прогон планировщика упирается в него, а не создаёт вторую копию
 * поручения на тот же день.
 *
 * @property int $id
 * @property int $recurrence_id
 * @property int $task_id
 * @property \Illuminate\Support\Carbon $occurrence_date
 */
class CrmTaskOccurrence extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurrence_id',
        'task_id',
        'occurrence_date',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
        ];
    }

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(CrmTaskRecurrence::class, 'recurrence_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class, 'task_id');
    }
}
