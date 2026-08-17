<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Токен подписного ICS-фида задач.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $scope
 * @property \Illuminate\Support\Carbon|null $last_fetched_at
 * @property-read User $user
 */
class CrmCalendarToken extends Model
{
    public const SCOPE_MINE = 'mine';

    public const SCOPE_DEPARTMENT = 'department';

    protected $fillable = ['user_id', 'scope', 'token'];

    protected function casts(): array
    {
        return [
            'last_fetched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Действующий токен пользователя для скоупа — создаётся при первом обращении.
     */
    public static function forUser(User $user, string $scope): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => (int) $user->getKey(), 'scope' => $scope],
            ['token' => self::generateToken()],
        );
    }

    /**
     * Перевыпуск: старая ссылка перестаёт работать сразу.
     */
    public function rotate(): self
    {
        $this->token = self::generateToken();
        $this->save();

        return $this;
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
