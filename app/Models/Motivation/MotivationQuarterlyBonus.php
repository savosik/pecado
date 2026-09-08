<?php

namespace App\Models\Motivation;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Квартальная премия отдела: достигнутая ступень, сумма и снимок расчёта.
 *
 * Живёт отдельно от месячного снимка: пункты 3.4 и 7.6 требуют невлияния на месячный
 * доход, и это должно быть видно в данных, а не только в коде.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $quarter_start
 * @property int $qualified_count
 * @property int $step_reached
 * @property string $amount
 * @property string $status
 * @property array<string, mixed>|null $snapshot
 */
class MotivationQuarterlyBonus extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationQuarterlyBonusFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'quarter_start',
        'qualified_count',
        'step_reached',
        'amount',
        'status',
        'snapshot',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'quarter_start' => 'date',
            'qualified_count' => 'integer',
            'step_reached' => 'integer',
            'amount' => 'decimal:2',
            'snapshot' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return HasMany<MotivationQuarterlyShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(MotivationQuarterlyShare::class, 'bonus_id');
    }

    /** @return HasMany<MotivationQuarterlyQualification, $this> */
    public function qualifications(): HasMany
    {
        return $this->hasMany(MotivationQuarterlyQualification::class, 'quarter_start', 'quarter_start');
    }

    public function isFrozen(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PAID], true);
    }

    /**
     * Первое число квартала, которому принадлежит месяц.
     */
    public static function quarterStartFor(Carbon $month): Carbon
    {
        return $month->copy()->startOfQuarter()->startOfDay();
    }
}
