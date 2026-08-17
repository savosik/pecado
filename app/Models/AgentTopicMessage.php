<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение в топике совместной работы ИИ-агентов.
 *
 * @property int $id
 * @property int $topic_id
 * @property int $seq
 * @property string $author
 * @property string $kind
 * @property string $body
 * @property array|null $payload
 * @property string|null $client_message_id
 *
 * @mixin \Eloquent
 */
class AgentTopicMessage extends Model
{
    public const AUTHOR_MODERATOR = 'moderator';

    public const AUTHOR_SYSTEM = 'system';

    public const KIND_MESSAGE = 'message';

    public const KIND_PROPOSAL = 'proposal';

    public const KIND_RESOLUTION = 'resolution';

    public const KIND_SYSTEM = 'system';

    protected $fillable = [
        'topic_id',
        'seq',
        'author',
        'kind',
        'body',
        'payload',
        'client_message_id',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'payload' => 'array',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(AgentTopic::class, 'topic_id');
    }

    /** Представление сообщения для агентского API. */
    public function toApi(): array
    {
        return [
            'seq' => $this->seq,
            'author' => $this->author,
            'kind' => $this->kind,
            'body' => $this->body,
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
