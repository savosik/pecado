<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Токен доступа менеджера к аналитическому MCP-серверу.
 *
 * @property int $id
 * @property string $name
 * @property int|null $user_id
 * @property string $token
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class AnalyticsToken extends Model
{
    protected $fillable = ['name', 'user_id', 'token', 'is_active', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Выпустить токен. Возвращает модель; открытое значение — в $model->token.
     * Значение хранится как есть (не хеш): это bearer для машинного доступа, и
     * его нужно уметь показать в списке владельцу, а не только проверить.
     */
    public static function issue(string $name, ?int $userId = null): self
    {
        return static::create([
            'name' => $name,
            'user_id' => $userId,
            'token' => Str::random(64),
            'is_active' => true,
        ]);
    }

    public function touchLastUsed(): void
    {
        // Не чаще раза в минуту — иначе каждый запрос агента порождал бы UPDATE.
        if (! $this->last_used_at || $this->last_used_at->diffInMinutes(now()) >= 1) {
            $this->forceFill(['last_used_at' => now()])->save();
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
