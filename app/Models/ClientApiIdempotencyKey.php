<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ключ идемпотентности клиентского API v1.
 *
 * @property int $id
 * @property int $user_id
 * @property string $operation
 * @property string $key
 * @property string $request_hash
 * @property string $status
 * @property int|null $status_code
 * @property array<string, mixed>|null $response
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property-read User $user
 */
class ClientApiIdempotencyKey extends Model
{
    use Prunable;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    /** Сколько живёт ключ: сутки — дольше агент не повторяет один и тот же запрос. */
    public const TTL_HOURS = 24;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'operation',
        'key',
        'request_hash',
        'status',
        'status_code',
        'response',
        'created_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'status_code' => 'integer',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }
}
