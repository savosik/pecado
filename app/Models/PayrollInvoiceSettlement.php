<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Накладная → срок оплаты → дата закрывающего платежа → задержка.
 *
 * Одна строка на реализацию. Сопоставление платежей пишет проектор, ручную дату —
 * РОП; действующая дата `settled_on` = ручная, иначе сопоставленная.
 *
 * @property int $id
 * @property int $shipment_id
 * @property string $shipment_uuid
 * @property string|null $erp_number
 * @property string|null $number_key
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $personal_manager_id
 * @property \Illuminate\Support\Carbon|null $shipped_on
 * @property string $total_amount
 * @property \Illuminate\Support\Carbon|null $due_on
 * @property string|null $due_source
 * @property string $matched_paid_amount
 * @property \Illuminate\Support\Carbon|null $matched_settled_on
 * @property list<array<string, mixed>>|null $payments
 * @property string|null $payment_status
 * @property \Illuminate\Support\Carbon|null $manual_settled_on
 * @property string|null $manual_comment
 * @property int|null $manual_by_user_id
 * @property \Illuminate\Support\Carbon|null $manual_set_at
 * @property \Illuminate\Support\Carbon|null $settled_on
 * @property string|null $settled_source
 * @property int|null $delay_calendar_days
 * @property int|null $delay_working_days
 * @property bool $needs_review
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property-read User|null $user
 * @property-read User|null $manualBy
 * @property-read PersonalManager|null $manager
 */
class PayrollInvoiceSettlement extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollInvoiceSettlementFactory> */
    use HasFactory;

    public const SOURCE_MATCHED = 'matched';

    public const SOURCE_MANUAL = 'manual';

    public const DUE_SCHEDULE = 'schedule';

    public const DUE_SHIPMENT_COLUMN = 'shipment_column';

    protected $fillable = [
        'shipment_id',
        'shipment_uuid',
        'erp_number',
        'number_key',
        'user_id',
        'company_id',
        'personal_manager_id',
        'shipped_on',
        'total_amount',
        'due_on',
        'due_source',
        'matched_paid_amount',
        'matched_settled_on',
        'payments',
        'payment_status',
        'manual_settled_on',
        'manual_comment',
        'manual_by_user_id',
        'manual_set_at',
        'settled_on',
        'settled_source',
        'delay_calendar_days',
        'delay_working_days',
        'needs_review',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'shipped_on' => 'date',
            'total_amount' => 'decimal:2',
            'due_on' => 'date',
            'matched_paid_amount' => 'decimal:2',
            'matched_settled_on' => 'date',
            'payments' => 'array',
            'manual_settled_on' => 'date',
            'manual_set_at' => 'datetime',
            'settled_on' => 'date',
            'delay_calendar_days' => 'integer',
            'delay_working_days' => 'integer',
            'needs_review' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<PersonalManager, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manualBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_by_user_id');
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
     * Закрытые с известной задержкой в заданном месяце.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSettledIn(Builder $query, \Carbon\CarbonInterface $month): Builder
    {
        $start = \Carbon\CarbonImmutable::instance($month)->startOfMonth();

        // whereDate, а не whereBetween по строкам: SQLite хранит date-каст с временем,
        // и «2026-07-31 00:00:00» строкой больше «2026-07-31».
        return $query
            ->whereNotNull('settled_on')
            ->whereNotNull('delay_working_days')
            ->whereDate('settled_on', '>=', $start->toDateString())
            ->whereDate('settled_on', '<=', $start->endOfMonth()->toDateString());
    }
}
