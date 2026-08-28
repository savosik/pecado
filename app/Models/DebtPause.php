<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Разблокировка лестницы долга до даты.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property Carbon $until
 * @property string $reason
 * @property int $created_by
 * @property Carbon|null $released_at
 * @property string|null $released_reason
 * @property-read User $user
 * @property-read Company|null $company
 * @property-read User $author
 */
class DebtPause extends Model
{
    use HasFactory;

    public const RELEASED_EXPIRED = 'expired';

    public const RELEASED_MANUAL = 'manual';

    public const RELEASED_PAID = 'paid';

    protected $fillable = [
        'user_id',
        'company_id',
        'until',
        'reason',
        'created_by',
        'released_at',
        'released_reason',
    ];

    protected $casts = [
        'until' => 'date',
        'released_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withoutGlobalScopes();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Действующие на дату: не снятые и не истёкшие. */
    public function scopeActive(Builder $query, ?Carbon $today = null): void
    {
        $query->whereNull('released_at')
            ->whereDate('until', '>=', ($today ?? Carbon::today())->toDateString());
    }

    /** Покрывает ли разблокировка данного контрагента (NULL — весь партнёр). */
    public function covers(?int $companyId): bool
    {
        return $this->company_id === null || $this->company_id === $companyId;
    }

    public function isActive(?Carbon $today = null): bool
    {
        return $this->released_at === null
            && $this->until->greaterThanOrEqualTo(($today ?? Carbon::today())->startOfDay());
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'company_name' => $this->company?->name,
            'until' => $this->until->format('d.m.Y'),
            'until_iso' => $this->until->toDateString(),
            'reason' => $this->reason,
            'author' => $this->author?->name,
            'created_at' => $this->created_at?->format('d.m.Y H:i'),
            'released_at' => $this->released_at?->format('d.m.Y H:i'),
            'released_reason' => $this->released_reason,
            'is_active' => $this->isActive(),
        ];
    }
}
