<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ручная строка дохода менеджера за месяц: позиция доп. дохода или корректировка РОПа.
 *
 * @property int $id
 * @property int $personal_manager_id
 * @property \Illuminate\Support\Carbon $period_month
 * @property string $component_key
 * @property string $label
 * @property string $qty
 * @property string $price
 * @property string $amount
 * @property string|null $comment
 * @property int|null $author_id
 * @property-read PersonalManager|null $manager
 * @property-read User|null $author
 */
class PayrollManualAdjustment extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollManualAdjustmentFactory> */
    use HasFactory;

    public const COMPONENT_EXTRA_INCOME = 'extra_income';

    public const COMPONENT_MANUAL_CORRECTION = 'manual_correction';

    /** @var list<string> */
    public const COMPONENTS = [
        self::COMPONENT_EXTRA_INCOME,
        self::COMPONENT_MANUAL_CORRECTION,
    ];

    protected $fillable = [
        'personal_manager_id',
        'period_month',
        'component_key',
        'label',
        'qty',
        'price',
        'amount',
        'comment',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'qty' => 'decimal:2',
            'price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PersonalManager, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public static function normalizeMonth(CarbonInterface $month): Carbon
    {
        return Carbon::instance($month)->startOfMonth()->startOfDay();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForManager(Builder $query, int $managerId): Builder
    {
        return $query->where('personal_manager_id', $managerId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPeriod(Builder $query, CarbonInterface $month): Builder
    {
        return $query->whereDate('period_month', self::normalizeMonth($month));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForComponent(Builder $query, string $componentKey): Builder
    {
        return $query->where('component_key', $componentKey);
    }
}
