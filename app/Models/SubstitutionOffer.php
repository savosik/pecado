<?php

namespace App\Models;

use App\Enums\Substitution\OfferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Подборка замен по заказу с недобором.
 *
 * Одна открытая подборка на заказ: пока клиент не отреагировал, новые волны
 * отмен из 1С дополняют её строками, а не плодят новые. Адресат и менеджер —
 * снимок на момент создания: перераспределение клиентов между менеджерами
 * не должно переносить уже отправленные подборки.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $manager_user_id
 * @property OfferStatus $status
 * @property string|null $dismiss_reason
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $viewed_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property array|null $result_order_ids
 */
class SubstitutionOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'user_id',
        'company_id',
        'manager_user_id',
        'status',
        'dismiss_reason',
        'expires_at',
        'viewed_at',
        'confirmed_at',
        'result_order_ids',
    ];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'expires_at' => 'datetime',
            'viewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'result_order_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $offer) {
            $offer->uuid ??= (string) Str::uuid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubstitutionOfferItem::class, 'offer_id');
    }

    /**
     * Живые подборки: их дополняют новые отмены и видит клиент.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [OfferStatus::PENDING, OfferStatus::VIEWED]);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isExpired(): bool
    {
        return $this->status === OfferStatus::EXPIRED
            || ($this->isOpen() && $this->expires_at->isPast());
    }
}
