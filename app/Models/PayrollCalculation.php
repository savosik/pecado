<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Снимок расчёта зарплаты менеджера за месяц.
 *
 * Черновик перезаписывается по событиям; утверждённый — заморожен, «переоткрыть»
 * создаёт новую версию. В снимке всё, чем считали: параметры, входы с уликами, разбор.
 *
 * @property int $id
 * @property int $personal_manager_id
 * @property \Illuminate\Support\Carbon $period_month
 * @property int $version
 * @property string $status
 * @property int|null $scheme_id
 * @property array<string, mixed> $params_effective
 * @property array<string, mixed> $inputs
 * @property array<string, mixed> $breakdown
 * @property string $total
 * @property array<string, mixed>|null $forecast
 * @property string|null $inputs_hash
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property int|null $approved_by_user_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $paid_by_user_id
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property string|null $comment
 */
class PayrollCalculation extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollCalculationFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_PAID];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Черновик',
        self::STATUS_APPROVED => 'Утверждено',
        self::STATUS_PAID => 'Выплачено',
    ];

    protected $fillable = [
        'personal_manager_id',
        'period_month',
        'version',
        'status',
        'scheme_id',
        'params_effective',
        'inputs',
        'breakdown',
        'total',
        'forecast',
        'inputs_hash',
        'computed_at',
        'approved_by_user_id',
        'approved_at',
        'paid_by_user_id',
        'paid_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'version' => 'integer',
            'params_effective' => 'array',
            'inputs' => 'array',
            'breakdown' => 'array',
            'total' => 'decimal:2',
            'forecast' => 'array',
            'computed_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(PayrollScheme::class, 'scheme_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public static function normalizeMonth(CarbonInterface $month): Carbon
    {
        return Carbon::instance($month)->startOfMonth()->startOfDay();
    }

    /**
     * Последняя версия снимка пары менеджер × месяц.
     *
     * Два запроса вместо одного намеренно: `SELECT * ... ORDER BY version DESC`
     * заставляет MySQL сортировать строки целиком, а строка снимка — это
     * несколько json-колонок на сотни килобайт (в `inputs` лежат все накладные
     * месяца). На dev такой запрос падал с «Out of sort memory» на трёх строках.
     * Сначала находим id по лёгкой выборке, потом читаем строку по ключу.
     */
    public static function latestFor(int $managerId, CarbonInterface $month, bool $lock = false): ?self
    {
        $id = self::query()
            ->forManager($managerId)
            ->forPeriod($month)
            ->orderByDesc('version')
            ->value('id');

        if ($id === null) {
            return null;
        }

        $query = self::query()->whereKey($id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFrozen(): bool
    {
        return $this->status !== self::STATUS_DRAFT;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
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
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }
}
