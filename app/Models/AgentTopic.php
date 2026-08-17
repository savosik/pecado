<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Топик совместной работы ИИ-агентов (агент сайта ↔ агент 1С).
 *
 * Админ создаёт топик с постановкой задачи и отдаёт каждой стороне её ссылку
 * с токеном. Агенты общаются строго по очереди (turn) — сервер отклоняет
 * сообщения вне хода. Закрытие задачи — рукопожатием proposal → resolution.
 *
 * @property int $id
 * @property string $title
 * @property string $task_body
 * @property string $status
 * @property string $site_token
 * @property string $erp_token
 * @property string $turn
 * @property \Illuminate\Support\Carbon|null $turn_started_at
 * @property int $last_seq
 * @property string|null $resolution
 * @property int|null $created_by
 *
 * @mixin \Eloquent
 */
class AgentTopic extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const ROLE_SITE = 'site';

    public const ROLE_ERP = 'erp';

    protected $fillable = [
        'title',
        'task_body',
        'status',
        'turn',
        'turn_started_at',
        'last_seq',
        'resolution',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'turn_started_at' => 'datetime',
            'last_seq' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AgentTopic $topic) {
            $topic->site_token = $topic->site_token ?: static::generateToken();
            $topic->erp_token = $topic->erp_token ?: static::generateToken();
            $topic->turn = $topic->turn ?: self::ROLE_SITE;
            $topic->turn_started_at = $topic->turn_started_at ?: now();
        });
    }

    public static function generateToken(): string
    {
        return hash('sha256', Str::random(40).microtime(true));
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentTopicMessage::class, 'topic_id')->orderBy('seq');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Роль стороны по её токену: site / erp, null — токен не отсюда. */
    public function roleForToken(string $token): ?string
    {
        return match ($token) {
            $this->site_token => self::ROLE_SITE,
            $this->erp_token => self::ROLE_ERP,
            default => null,
        };
    }

    public static function otherRole(string $role): string
    {
        return $role === self::ROLE_SITE ? self::ROLE_ERP : self::ROLE_SITE;
    }

    /** Диалог завершён — новые сообщения агентов не принимаются. */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    /**
     * Добавить сообщение со следующим порядковым номером.
     * Вызывать внутри транзакции с заблокированной строкой топика.
     */
    public function appendMessage(string $author, string $kind, string $body, ?array $payload = null, ?string $clientMessageId = null): AgentTopicMessage
    {
        $this->last_seq++;
        $this->save();

        return $this->messages()->create([
            'seq' => $this->last_seq,
            'author' => $author,
            'kind' => $kind,
            'body' => $body,
            'payload' => $payload,
            'client_message_id' => $clientMessageId,
        ]);
    }
}
