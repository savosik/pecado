<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Отклонение параметров компонента зарплаты по менеджеру: постоянное или на месяц.
 *
 * Хранит только отличающиеся от нижнего слоя ключи. Совпадение с нижним слоем —
 * это отсутствие строки, а не строка с теми же числами.
 *
 * @property int $id
 * @property int $personal_manager_id
 * @property \Illuminate\Support\Carbon $period_month
 * @property string $component_key
 * @property array<string, mixed> $params
 * @property int|null $updated_by_user_id
 * @property string|null $comment
 */
class PayrollParamOverride extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollParamOverrideFactory> */
    use HasFactory;

    protected $fillable = [
        'personal_manager_id',
        'period_month',
        'component_key',
        'params',
        'updated_by_user_id',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'params' => 'array',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Дата-маркер «постоянного» отклонения менеджера.
     */
    public static function permanentMonth(): Carbon
    {
        return Carbon::parse((string) config('payroll.permanent_month', '1970-01-01'))->startOfDay();
    }

    /**
     * Ключ периода для записи: первое число месяца либо маркер постоянного слоя.
     */
    public static function periodKey(?CarbonInterface $month): Carbon
    {
        return $month === null
            ? self::permanentMonth()
            : Carbon::instance($month)->startOfMonth()->startOfDay();
    }

    public function isPermanent(): bool
    {
        return $this->period_month->isSameDay(self::permanentMonth());
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
    public function scopeForPeriod(Builder $query, ?CarbonInterface $month): Builder
    {
        return $query->whereDate('period_month', self::periodKey($month));
    }
}
