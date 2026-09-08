<?php

namespace App\Models\Motivation;

use App\Models\PersonalManager;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Приказ об установлении Личного плана на квартал: обоснование, а не сам план.
 *
 * Значения плана после утверждения записываются в crm_sales_plans — единственное место,
 * откуда план читают все экраны CRM. Второй правды о плане не заводим.
 *
 * previous_values хранится затем, что действующие планы поставлены по другой методике
 * (сверху вниз от цифры компании) и расходятся с формульными до двукратного: руководитель
 * утверждает план, видя, чем он отличается от прежнего.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $quarter_start
 * @property int $personal_manager_id
 * @property int $version
 * @property string $median_per_day
 * @property array<string, mixed> $working_days
 * @property array<string, mixed> $seasonal
 * @property string $growth_rate
 * @property string|null $overperformance_carry
 * @property string|null $base_change
 * @property string|null $previous_quarter_total
 * @property bool $decline_limited
 * @property string|null $decline_limit_waived_reason
 * @property array<string, mixed> $values
 * @property array<string, mixed>|null $previous_values
 * @property string $status
 */
class MotivationPlanOrder extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationPlanOrderFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'quarter_start',
        'personal_manager_id',
        'version',
        'median_per_day',
        'working_days',
        'seasonal',
        'growth_rate',
        'overperformance_carry',
        'base_change',
        'previous_quarter_total',
        'decline_limited',
        'decline_limit_waived_reason',
        'values',
        'previous_values',
        'status',
        'comment',
        'author_id',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'quarter_start' => 'date',
            'version' => 'integer',
            'median_per_day' => 'decimal:2',
            'working_days' => 'array',
            'seasonal' => 'array',
            'growth_rate' => 'decimal:5',
            'overperformance_carry' => 'decimal:2',
            'base_change' => 'decimal:2',
            'previous_quarter_total' => 'decimal:2',
            'decline_limited' => 'boolean',
            'values' => 'array',
            'previous_values' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PersonalManager, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForQuarter(Builder $query, CarbonInterface $quarterStart): Builder
    {
        return $query->whereDate('quarter_start', CarbonImmutable::instance($quarterStart)->startOfQuarter());
    }
}
