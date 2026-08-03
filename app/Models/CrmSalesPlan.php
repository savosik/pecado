<?php

namespace App\Models;

use App\Enums\Crm\PlanTarget;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Месячный план продаж: отделу, менеджеру или клиенту.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $period_month
 * @property PlanTarget $target_type
 * @property int $target_id
 * @property string $amount
 * @property int|null $author_id
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $author
 */
class CrmSalesPlan extends Model
{
    /** @use HasFactory<\Database\Factories\CrmSalesPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'period_month',
        'target_type',
        'target_id',
        'amount',
        'author_id',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'target_type' => PlanTarget::class,
            // decimal, а не float: план — деньги, и округление до копеек должно быть
            // предсказуемым, а не зависеть от представления double.
            'amount' => 'decimal:2',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Первое число месяца — единственная допустимая форма периода.
     *
     * Нормализация живёт в модели, а не только в сервисе: план, записанный
     * серединой месяца, разошёлся бы с unique-индексом и продублировался.
     */
    public static function normalizeMonth(CarbonInterface $month): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::instance($month)->startOfMonth()->startOfDay();
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
    public function scopeForManager(Builder $query, int $managerId): Builder
    {
        return $query->where('target_type', PlanTarget::MANAGER->value)->where('target_id', $managerId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('target_type', PlanTarget::CLIENT->value)->where('target_id', $clientId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDepartment(Builder $query): Builder
    {
        return $query->where('target_type', PlanTarget::DEPARTMENT->value);
    }

    /**
     * Значение target_id для записи в БД.
     *
     * У отдела цели нет, но колонка не nullable: NULL сломал бы unique-индекс
     * (MySQL считает NULL-ы различными), поэтому отдел живёт под нулём.
     */
    public static function targetKey(PlanTarget $type, ?int $targetId): int
    {
        return $type->needsTarget() ? (int) $targetId : 0;
    }

    /**
     * Сумма плана числом — для арифметики и сравнений с фактом.
     */
    public function amountValue(): float
    {
        return (float) $this->amount;
    }
}
