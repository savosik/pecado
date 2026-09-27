<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ход треда: реплика клиента, ответ модели или операторская инструкция.
 *
 * `content` — массив блоков в wire-формате Messages API. Для ответа модели
 * это `response.content` целиком (включая `thinking` с подписями, вызовы и
 * результаты MCP, блок компакции): он уходит в следующий запрос байт-в-байт,
 * иначе подписи блоков мышления перестают сходиться. `text` — то, что видит
 * человек.
 *
 * @property int $id
 * @property int $thread_id
 * @property string $role
 * @property string $kind
 * @property list<array<string, mixed>> $content
 * @property string|null $text
 * @property string $status
 * @property string|null $error_code
 * @property array<string, mixed>|null $usage
 * @property string $cost
 * @property string|null $anthropic_id
 * @property string|null $model
 * @property string|null $stop_reason
 * @property-read ChatThread $thread
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChatAttachment> $attachments
 */
class ChatMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const KIND_MESSAGE = 'message';

    public const KIND_CONFIRMATION = 'confirmation';

    public const KIND_NOTE = 'note';

    public const STATUS_PENDING = 'pending';

    public const STATUS_STREAMING = 'streaming';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'thread_id',
        'role',
        'kind',
        'content',
        'text',
        'status',
        'error_code',
        'usage',
        'cost',
        'anthropic_id',
        'model',
        'stop_reason',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'usage' => 'array',
            'cost' => 'decimal:6',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /**
     * Плоский текст из блоков: только `text`, служебные блоки не показываются.
     *
     * @param  list<array<string, mixed>>  $content
     */
    public static function textOf(array $content): string
    {
        $parts = [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }
}
