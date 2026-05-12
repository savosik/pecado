<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $base_url
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
    protected $fillable = [
        'user_id',
        'name',
        'token',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    protected $appends = ['base_url'];

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
     * Get the user that owns this API token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
