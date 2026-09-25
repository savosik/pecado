<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подтверждение необратимой операции в чате-помощнике.
 *
 * Модель вызывает `orders.create` — MCP-сервер видит токен вида `assistant`,
 * подтверждения нет, и вместо выполнения создаёт эту запись с ответом
 * `confirmation_required`. Клиент видит карточку и жмёт «Оформить», модель
 * повторяет вызов с теми же аргументами — запись найдена по хешу и одобрена,
 * операция выполняется с ключом идемпотентности отсюда.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $user_id
 * @property string $operation
 * @property array<string, mixed> $arguments
 * @property string $arguments_hash
 * @property string $idempotency_key
 * @property string $status
 * @property array<string, mixed>|null $summary
 * @property array<string, mixed>|null $result
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property-read ChatThread $thread
 */
class ChatConfirmation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_USED = 'used';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'thread_id',
        'user_id',
        'operation',
        'arguments',
        'arguments_hash',
        'idempotency_key',
        'status',
        'summary',
        'result',
        'decided_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'summary' => 'array',
            'result' => 'array',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->expires_at->isFuture();
    }

    /**
     * Хеш аргументов: ключи сортируются рекурсивно, чтобы порядок полей
     * в повторном вызове модели не менял отпечаток.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function hashArguments(string $operation, array $arguments): string
    {
        return hash('sha256', $operation.'|'.json_encode(self::normalize($arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function normalize(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'status' => $this->status,
            'summary' => $this->summary,
            'result' => $this->result,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
