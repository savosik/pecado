<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ссылка входа в кабинет склада без пароля (pick-17).
 *
 * @property int $id
 * @property string $name
 * @property int $user_id
 * @property int $session_version
 * @property ?\Illuminate\Support\Carbon $revoked_at
 */
class WmsAccessLink extends Model
{
    /** Ключи сессии, по которым middleware узнаёт вход по ссылке. */
    public const SESSION_LINK = 'wms_link_id';

    public const SESSION_VERSION = 'wms_link_version';

    protected $fillable = ['name', 'user_id', 'token_hash', 'token_encrypted', 'session_version', 'uses_count', 'last_used_at', 'rotated_at', 'revoked_at', 'created_by'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'rotated_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
