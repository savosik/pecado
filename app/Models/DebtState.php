<?php

namespace App\Models;

use App\Enums\DebtLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ступень долга по паре партнёр × контрагент.
 *
 * Пишет только `DebtStateService`; всё остальное читает. Строка с
 * `company_id = NULL` — сводка по партнёру.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property DebtLevel $level
 * @property DebtLevel|null $previous_level
 * @property \Illuminate\Support\Carbon|null $since
 * @property \Illuminate\Support\Carbon|null $level_changed_at
 * @property string $overdue_amount
 * @property string $overdue_total
 * @property string $debt_amount
 * @property \Illuminate\Support\Carbon|null $oldest_due_date
 * @property int $age_days
 * @property int $lines_count
 * @property string|null $reason
 * @property bool $is_stale
 * @property bool $dry_run
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property-read User $user
 * @property-read Company|null $company
 */
class DebtState extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'level',
        'previous_level',
        'since',
        'level_changed_at',
        'overdue_amount',
        'overdue_total',
        'debt_amount',
        'oldest_due_date',
        'age_days',
        'lines_count',
        'reason',
        'is_stale',
        'dry_run',
        'computed_at',
    ];

    protected $casts = [
        'level' => DebtLevel::class,
        'previous_level' => DebtLevel::class,
        'since' => 'date',
        'level_changed_at' => 'datetime',
        'oldest_due_date' => 'date',
        'age_days' => 'integer',
        'lines_count' => 'integer',
        'is_stale' => 'boolean',
        'dry_run' => 'boolean',
        'computed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withoutGlobalScopes();
    }

    /** Сводные строки партнёров. */
    public function scopePartners(Builder $query): void
    {
        $query->whereNull('company_id');
    }

    /** Строки контрагентов. */
    public function scopeContractors(Builder $query): void
    {
        $query->whereNotNull('company_id');
    }

    /** Только боевой расчёт — то, на что имеют право опираться гейт и кабинет. */
    public function scopeLive(Builder $query): void
    {
        $query->where('dry_run', false);
    }

    public function scopeRestricting(Builder $query): void
    {
        $query->whereIn('level', [
            DebtLevel::NO_PREORDERS->value,
            DebtLevel::NO_ORDERS->value,
            DebtLevel::HOLD->value,
        ]);
    }

    public function isPartnerRow(): bool
    {
        return $this->company_id === null;
    }

    /**
     * Плоское представление для Inertia.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_id' => $this->company_id,
            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'level_color' => $this->level->color(),
            'hint' => $this->level->clientHint(),
            'previous_level' => $this->previous_level?->value,
            'since' => $this->since?->format('d.m.Y'),
            'since_iso' => $this->since?->toDateString(),
            'level_changed_at' => $this->level_changed_at?->format('d.m.Y'),
            'overdue_amount' => (float) $this->overdue_amount,
            'overdue_total' => (float) $this->overdue_total,
            'debt_amount' => (float) $this->debt_amount,
            'oldest_due_date' => $this->oldest_due_date?->format('d.m.Y'),
            'age_days' => $this->age_days,
            'lines_count' => $this->lines_count,
            'reason' => $this->reason,
            'is_stale' => $this->is_stale,
            'dry_run' => $this->dry_run,
            'computed_at' => $this->computed_at?->format('d.m.Y H:i'),
        ];
    }
}
