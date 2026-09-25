<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $kind
 * @property string $token
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $base_url
 * @property-read string $v1_base_url
 * @property-read \App\Models\User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApiToken whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ApiToken extends Model
{
    /** Личный ключ: клиент выдал в кабинете, бессрочный. */
    public const KIND_PERSONAL = 'personal';

    /** Токен чата-помощника: выпущен воркером на тред, с TTL, клиенту не показывается. */
    public const KIND_ASSISTANT = 'assistant';

    protected $fillable = [
        'user_id',
        'name',
        'kind',
        'token',
        'is_active',
        'expires_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function isAssistant(): bool
    {
        return $this->kind === self::KIND_ASSISTANT;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Токены, которые показываются клиенту в кабинете: только личные.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ApiToken>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ApiToken>
     */
    public function scopePersonal(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('kind', self::KIND_PERSONAL);
    }

    protected $appends = ['base_url', 'v1_base_url'];

    /**
     * Boot the model — auto-generate token on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ApiToken $model) {
            if (empty($model->token)) {
                $model->token = static::generateToken($model->user_id);
            }
        });
    }

    /**
     * Generate a unique SHA-256 token.
     */
    public static function generateToken(?int $userId = null): string
    {
        return hash('sha256', ($userId ?? 0).microtime(true).Str::random(32));
    }

    /**
     * Regenerate the token hash.
     */
    public function regenerateToken(): self
    {
        $this->token = static::generateToken($this->user_id);
        $this->save();

        return $this;
    }

    /**
     * Mark as recently used.
     */
    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Get the base URL for this API token.
     */
    public function getBaseUrlAttribute(): string
    {
        return url("/api/client-api/{$this->token}");
    }

    /**
     * Базовый адрес клиентского API v1: ключ передаётся в заголовке Bearer, а не в адресе.
     */
    public function getV1BaseUrlAttribute(): string
    {
        return url('/api/client/v1');
    }

    /**
     * Get the user that owns this API token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
