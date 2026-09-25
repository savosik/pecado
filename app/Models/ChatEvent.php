<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Событие воронки помощника — без текста, только факт.
 *
 * По этим строкам считается «видели → открыли → написали → оформили» и
 * какие реплики иконки на каких страницах ведут в диалог.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $thread_id
 * @property string $event
 * @property string|null $page
 * @property string|null $prompt_key
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ChatEvent extends Model
{
    public const SHOWN = 'shown';

    public const BUBBLE_SHOWN = 'bubble_shown';

    public const BUBBLE_CLICKED = 'bubble_clicked';

    public const BUBBLE_DISMISSED = 'bubble_dismissed';

    public const OPENED = 'opened';

    public const FIRST_MESSAGE = 'first_message';

    public const CONFIRMED_ACTION = 'confirmed_action';

    public const VOICE_USED = 'voice_used';

    /** События, которые фронт может прислать сам; остальные пишет сервер. */
    public const CLIENT_EVENTS = [
        self::SHOWN,
        self::BUBBLE_SHOWN,
        self::BUBBLE_CLICKED,
        self::BUBBLE_DISMISSED,
        self::OPENED,
        self::VOICE_USED,
    ];

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'thread_id',
        'event',
        'page',
        'prompt_key',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }
}
