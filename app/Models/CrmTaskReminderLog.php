<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отметка «напоминание отправлено»: задача × получатель × повод × канал.
 *
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $kind
 * @property string $channel
 * @property \Illuminate\Support\Carbon $sent_at
 */
class CrmTaskReminderLog extends Model
{
    public const KIND_ASSIGNED = 'assigned';

    public const KIND_DUE = 'due';

    public const KIND_DUE_SOON = 'due_soon';

    public const KIND_OVERDUE = 'overdue';

    public const CHANNEL_TOAST = 'toast';

    public const CHANNEL_MAIL = 'mail';

    public const CHANNEL_PUSH = 'push';

    public $timestamps = false;

    protected $fillable = ['task_id', 'user_id', 'kind', 'channel', 'sent_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CrmTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
