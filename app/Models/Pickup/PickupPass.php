<?php

namespace App\Models\Pickup;

use App\Models\GoodsIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Пропуск на самовывоз (pick-09): отвечает кладовщику «кому отдаю» и «что отдать».
 *
 * @property int $id
 * @property int $user_id
 * @property string $scope
 * @property string $code
 * @property string $status
 * @property \Illuminate\Support\Carbon $expires_at
 */
class PickupPass extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_SELECTED = 'selected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_USED = 'used';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Действует',
        self::STATUS_USED => 'Использован',
        self::STATUS_REVOKED => 'Отозван',
        self::STATUS_EXPIRED => 'Истёк',
    ];

    protected $fillable = [
        'user_id', 'scope', 'token_hash', 'token_encrypted', 'code', 'status', 'expires_at', 'courier_name',
        'courier_phone', 'note', 'source', 'used_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PickupPassItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PickupPassItem::class);
    }

    /** @return BelongsToMany<GoodsIssue, $this> */
    public function goodsIssues(): BelongsToMany
    {
        return $this->belongsToMany(GoodsIssue::class, 'pickup_pass_items')->withTimestamps();
    }

    /** @return HasMany<PickupHandover, $this> */
    public function handovers(): HasMany
    {
        return $this->hasMany(PickupHandover::class);
    }

    /**
     * Действующие: статус active и срок не вышел (статус expired проставляется лениво).
     *
     * @param  Builder<PickupPass>  $query
     * @return Builder<PickupPass>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at->isFuture();
    }

    /** Статус с учётом срока: истёкший active показываем как expired. */
    public function getEffectiveStatusAttribute(): string
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at->isPast()
            ? self::STATUS_EXPIRED
            : $this->status;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->effective_status] ?? $this->status;
    }

    /** «482 913» — так код легче прочитать вслух. */
    public function getCodeDisplayAttribute(): string
    {
        return substr($this->code, 0, 3).' '.substr($this->code, 3);
    }
}
