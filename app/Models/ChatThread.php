<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тред чата-помощника: один разговор клиента с агентом.
 *
 * История треда — append-only: ходы (`chat_messages`) хранят блоки ответа
 * как есть и повторно отправляются в API в том же порядке. Счётчики токенов
 * и стоимость складываются из usage каждого хода.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property int|null $token_id
 * @property string|null $title
 * @property string $status
 * @property array<string, mixed>|null $page
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $compacted_at
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost
 * @property string|null $summary
 * @property-read User $user
 * @property-read Company|null $company
 * @property-read ApiToken|null $token
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChatMessage> $messages
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChatConfirmation> $confirmations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChatAttachment> $attachments
 */
class ChatThread extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'company_id',
        'token_id',
        'title',
        'status',
        'page',
        'last_message_at',
        'closed_at',
        'compacted_at',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'cost',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'array',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'compacted_at' => 'datetime',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost' => 'decimal:6',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'token_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'thread_id')->orderBy('id');
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(ChatConfirmation::class, 'thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'thread_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Сумма всех токенов треда — для предела на тред. */
    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens + $this->cache_read_tokens + $this->cache_write_tokens;
    }

    /** @param  Builder<ChatThread>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Только треды, в которых хоть что-то написано: пустые заводятся
     * открытием панели и в истории клиента — шум.
     *
     * @param  Builder<ChatThread>  $query
     */
    public function scopeWithMessages(Builder $query): Builder
    {
        return $query->has('messages');
    }

    /** @param  Builder<ChatThread>  $query */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }
}
