<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Токен ИИ-агента закупщика для работы с уценкой через /mcp/purchasing.
 *
 * Токен не просто пропускает в API — он превращается в конкретного закупщика:
 * после аутентификации работают его права `defects.*`, а автор каждой цены
 * фиксируется в product_defects.priced_by. Агент физически не может больше
 * своего владельца, и это не требует второй копии правил доступа.
 *
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property string $token
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property-read User|null $user
 */
class PurchasingAgentToken extends Model
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
     * Выпустить токен. Владелец обязателен: у токена есть право записи,
     * и запись без установленного автора недопустима.
     */
    public static function issue(string $name, int $userId): self
    {
        return static::create([
            'name' => $name,
            'user_id' => $userId,
            // Значение хранится как есть, а не хешем: это bearer для машинного
            // доступа, и его нужно уметь показать владельцу, а не только
            // проверить при обращении.
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
