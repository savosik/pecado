<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Токен ИИ-агента менеджера для пишущего доступа в CRM.
 *
 * Токен не просто пропускает в API — он превращается в конкретного сотрудника:
 * после аутентификации работают уже существующие права, политики и скоуп
 * `User::visibleInCrm()`. Агент физически не может больше своего менеджера,
 * и это не требует второй копии правил доступа, которая разошлась бы с первой.
 *
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property string $token
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property-read User|null $user
 */
class CrmAgentToken extends Model
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
     * Выпустить токен. Владелец обязателен: запись без установленного автора
     * недопустима, поэтому токена «ничей» здесь не бывает — в отличие от
     * аналитических, где user_id nullable.
     */
    public static function issue(string $name, int $userId): self
    {
        return static::create([
            'name' => $name,
            'user_id' => $userId,
            // Значение хранится как есть, а не хешем: это bearer для машинного
            // доступа, и его нужно уметь показать владельцу в списке, а не
            // только проверить при обращении.
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
