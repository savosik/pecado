<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ссылка-хеш на пульт Agent Hub.
 *
 * Одна ссылка открывает список всех топиков ИИ-агентов, создание новых и
 * управление ими — без авторизации на сайте. Ею же внешний агент (например,
 * агент 1С) авторизует API создания топиков. Защита — секретность хеша,
 * поэтому ссылку выдают поимённо и отзывают целиком.
 *
 * @property int $id
 * @property string $token
 * @property string $label
 * @property string|null $note
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 *
 * @mixin \Eloquent
 */
class AgentHubLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'note',
        'created_by',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AgentHubLink $link) {
            $link->token = $link->token ?: static::generateToken();
        });
    }

    public static function generateToken(): string
    {
        return hash('sha256', Str::random(40).microtime(true));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(AgentTopic::class, 'hub_link_id');
    }

    /** Адрес пульта для этой ссылки. */
    public function url(): string
    {
        return url("/agent-hub/{$this->token}");
    }

    /**
     * Отметка использования. Пишем не чаще раза в минуту: пульт опрашивает
     * сервер каждые несколько секунд, а точность «когда заходили» не нужна.
     */
    public function touchUsage(): void
    {
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
