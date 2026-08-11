<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Соглашение с клиентом из 1С (v16.0.0).
 *
 * Заняло место запланированного «договора»: договоров в базе 75 и 97,8 % реализаций
 * идут без них, соглашений — 5 102 при полном покрытии. Договор остался парой полей
 * `contract_uuid` / `contract_name` в самом движении регистра.
 *
 * ⚠️ Соглашение НЕ измерение регистра взаиморасчётов. 1С берёт его из документа-
 * регистратора, поэтому изменение соглашения задним числом сдвигает группировку уже
 * проведённых движений. Отсюда правило: соглашение — это фильтр и группировка,
 * а сальдо считается по оси контрагент × организация × валюта. Строить баланс
 * «по соглашению» нельзя — он будет разъезжаться незаметно.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $organization_id
 * @property string|null $partner_uuid
 * @property string|null $contractor_uuid
 * @property string|null $organization_uuid
 * @property string|null $tax_id
 * @property string|null $number
 * @property \Illuminate\Support\Carbon|null $date
 * @property string|null $name
 * @property string|null $currency_code
 * @property string|null $settlement_procedure
 * @property numeric|null $credit_limit
 * @property int|null $deferral_days
 * @property string $status
 * @property int|null $revision
 * @property \Illuminate\Support\Carbon|null $erp_created_at
 * @property \Illuminate\Support\Carbon|null $erp_updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SettlementEntry> $settlementEntries
 * @property-read string $settlement_procedure_label
 * @property-read string $status_label
 * @property-read string $display_name
 *
 * @method static \Database\Factories\AgreementFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class Agreement extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Соглашение действует. */
    public const STATUS_ACTIVE = 'active';

    /** Соглашение закрыто. */
    public const STATUS_CLOSED = 'closed';

    /**
     * Порядок расчётов из соглашения. Перечень согласован с 1С в круге 4:
     * это реальный состав их справочника, а не наше предположение.
     *
     * @var array<string, string>
     */
    public const SETTLEMENT_PROCEDURE_LABELS = [
        'orders' => 'По заказам',
        'advance_orders_debt_invoices' => 'По авансам-заказам и накладным',
        'settlement_documents' => 'По расчётным документам',
    ];

    protected $fillable = [
        'uuid',
        'user_id',
        'company_id',
        'organization_id',
        'partner_uuid',
        'contractor_uuid',
        'organization_uuid',
        'tax_id',
        'number',
        'date',
        'name',
        'currency_code',
        'settlement_procedure',
        'credit_limit',
        'deferral_days',
        'status',
        'revision',
        'erp_created_at',
        'erp_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'credit_limit' => 'decimal:2',
            'deferral_days' => 'integer',
            'revision' => 'integer',
            'erp_created_at' => 'datetime',
            'erp_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function settlementEntries(): HasMany
    {
        return $this->hasMany(SettlementEntry::class);
    }

    /**
     * Действующие соглашения.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Порядок расчётов по-русски. Незнакомый код показываем как есть: перечень
     * на стороне 1С может пополниться, и прятать значение за «—» вреднее,
     * чем показать сырой код.
     */
    public function getSettlementProcedureLabelAttribute(): string
    {
        if ($this->settlement_procedure === null) {
            return 'Не задан';
        }

        return self::SETTLEMENT_PROCEDURE_LABELS[$this->settlement_procedure] ?? $this->settlement_procedure;
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status === self::STATUS_CLOSED ? 'Закрыто' : 'Действует';
    }

    /**
     * Отображаемое имя: наименование из 1С, иначе номер, иначе UUID.
     * Соглашения без наименования встречаются, а показать что-то нужно всегда.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name
            ?? ($this->number !== null ? 'Соглашение №'.$this->number : $this->uuid);
    }
}
