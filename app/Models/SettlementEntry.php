<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Строка регистра взаиморасчётов (v16.0.0).
 *
 * Одна строка — одна хозяйственная операция (`nature = fact`) либо один плановый
 * платёж по графику оплаты (`nature = plan`). Плоская лента вместо графа связей
 * «платёж → реализация → заказ → зачёт»: любой денежный ответ получается
 * суммированием `amount`, а не обходом связей.
 *
 * Знак содержательный: «+» — в пользу клиента (оплата, возврат товара),
 * «−» — в нашу пользу (реализация, возврат денег). `SUM(amount)` по фактическим
 * строкам равна балансу контрагента, а отрицательный баланс означает долг.
 * Соглашение то же, что у `ContractorBalance::current_balance`, поэтому старые
 * экраны и инструкции MCP-агента при переходе не переворачиваются.
 *
 * Что сайт НЕ делает: не раскладывает платежи по строкам графика. `settled_amount`
 * приходит из 1С (регистр `РасчетыСКлиентамиПоСрокам`). Прежняя самодельная FIFO-раскладка
 * дала клиенту 5,53 млн ₽ просрочки вместо 478 тыс ₽ — повторять это нельзя.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $revision
 * @property string $source
 * @property string $nature
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $date В БД колонка NOT NULL, но у несохранённой модели атрибута ещё нет
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $organization_id
 * @property int|null $agreement_id
 * @property string|null $partner_uuid
 * @property string|null $contractor_uuid
 * @property string|null $organization_uuid
 * @property string|null $agreement_uuid
 * @property string|null $agreement_name
 * @property string|null $contract_uuid
 * @property string|null $contract_name
 * @property string|null $settlement_object_kind
 * @property string|null $settlement_object_uuid
 * @property string|null $settlement_object_name
 * @property numeric $amount
 * @property string $currency_code
 * @property numeric|null $amount_rub
 * @property numeric $settled_amount
 * @property bool $is_settled_derived
 * @property numeric|null $document_settled_amount
 * @property numeric $debit
 * @property numeric $credit
 * @property string|null $movement_kind
 * @property string|null $document_type
 * @property int|null $document_id
 * @property string|null $document_uuid
 * @property string|null $document_kind
 * @property string|null $document_number
 * @property \Illuminate\Support\Carbon|null $document_date
 * @property int|null $line_number
 * @property string|null $comment
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $erp_created_at
 * @property \Illuminate\Support\Carbon|null $erp_updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Organization|null $organization
 * @property-read \App\Models\Agreement|null $agreement
 * @property-read \Illuminate\Database\Eloquent\Model|null $document
 * @property-read float $unsettled_amount
 * @property-read bool $is_overdue
 * @property-read string $type_label
 * @property-read string $direction_label
 * @property-read string $document_label
 *
 * @method static \Database\Factories\SettlementEntryFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class SettlementEntry extends Model
{
    use HasFactory;

    /** Свершившаяся операция: влияет на баланс. */
    public const NATURE_FACT = 'fact';

    /** Строка графика оплаты: даёт прогноз и просрочку, в баланс не входит. */
    public const NATURE_PLAN = 'plan';

    /** Начальное сальдо на дату начала ленты движений. */
    public const TYPE_OPENING_BALANCE = 'opening_balance';

    /** Реализация клиенту — минус. */
    public const TYPE_SHIPMENT = 'shipment';

    /** Поступление средств от клиента — плюс. */
    public const TYPE_PAYMENT_IN = 'payment_in';

    /** Физический возврат денег клиенту — минус. */
    public const TYPE_PAYMENT_OUT = 'payment_out';

    /** Возврат товара по накладной (зачёт брака) — плюс. */
    public const TYPE_GOODS_RETURN = 'goods_return';

    /** Продажа через комиссионера — минус. */
    public const TYPE_COMMISSION_SALE = 'commission_sale';

    /** Взаимозачёт, корректировка задолженности или реализации — знак по смыслу. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    /** Плановый платёж по графику оплаты. Единственный тип природы plan. */
    public const TYPE_PAYMENT_DUE = 'payment_due';

    /**
     * Погрешность сравнения денег. Та же, что у Payment::EPSILON
     * и ShipmentPaymentSchedule::EPSILON — разъехавшиеся допуски дали бы строку,
     * «закрытую» в одном разделе и «непогашенной» в другом.
     */
    public const EPSILON = 0.01;

    /** Состояния плановой строки — те же три, что показывала карточка графика. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_OPENING_BALANCE => 'Начальное сальдо',
        self::TYPE_SHIPMENT => 'Реализация',
        self::TYPE_PAYMENT_IN => 'Поступление средств',
        self::TYPE_PAYMENT_OUT => 'Возврат средств',
        self::TYPE_GOODS_RETURN => 'Возврат товара',
        self::TYPE_COMMISSION_SALE => 'Продажа через комиссионера',
        self::TYPE_ADJUSTMENT => 'Корректировка',
        self::TYPE_PAYMENT_DUE => 'Плановый платёж',
    ];

    /** @var array<string, string> */
    public const DOCUMENT_KIND_LABELS = [
        'shipment' => 'Реализация товаров и услуг',
        'service_sale' => 'Реализация услуг и прочих активов',
        'order' => 'Заказ клиента',
        'initial' => 'Первичный документ',
        'payment' => 'Платёжный документ',
        'goods_return' => 'Возврат товаров от клиента',
        'netting' => 'Взаимозачёт задолженности',
        'debt_adjustment' => 'Корректировка задолженности',
        'sale_adjustment' => 'Корректировка реализации',
        'commission_report' => 'Отчёт комиссионера',
        'commission_writeoff' => 'Отчёт комиссионера о списании',
        'other' => 'Прочий документ',
    ];

    protected $fillable = [
        'uuid',
        'revision',
        'source',
        'nature',
        'type',
        'date',
        'valid_until',
        'user_id',
        'company_id',
        'organization_id',
        'agreement_id',
        'partner_uuid',
        'contractor_uuid',
        'organization_uuid',
        'agreement_uuid',
        'agreement_name',
        'contract_uuid',
        'contract_name',
        'settlement_object_kind',
        'settlement_object_uuid',
        'settlement_object_name',
        'amount',
        'currency_code',
        'amount_rub',
        'settled_amount',
        'paying_amount',
        'is_settled_derived',
        'document_settled_amount',
        'movement_kind',
        'document_type',
        'document_id',
        'document_uuid',
        'document_kind',
        'document_number',
        'document_date',
        'line_number',
        'comment',
        'meta',
        'erp_created_at',
        'erp_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'valid_until' => 'date',
            'document_date' => 'date',
            'amount' => 'decimal:2',
            'amount_rub' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'paying_amount' => 'decimal:2',
            'document_settled_amount' => 'decimal:2',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'is_settled_derived' => 'boolean',
            'revision' => 'integer',
            'line_number' => 'integer',
            'meta' => 'array',
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

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /**
     * Документ-регистратор на сайте. Связь мягкая: документа может не быть вовсе
     * (отчёт комиссионера), и строка обязана читаться без него — для этого
     * продублированы `document_number`, `document_date`, `document_kind`.
     */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Свершившиеся операции — то, из чего складывается баланс.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFacts(Builder $query): void
    {
        $query->where('nature', self::NATURE_FACT);
    }

    /**
     * Плановые платежи — то, из чего складываются календарь и просрочка.
     *
     * @param  Builder<self>  $query
     */
    public function scopePlans(Builder $query): void
    {
        $query->where('nature', self::NATURE_PLAN);
    }

    /**
     * Плановые строки, по которым ещё ждём денег.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        // Константа подставляется в SQL, а не биндится параметром: у выражения
        // `amount - settled_amount` нет аффинности типа, и SQLite сравнил бы число
        // с привязанной строкой '0.01' — текст там всегда больше числа, из-за чего
        // условие не находило бы ни одной строки. Та же грабля,
        // что в ShipmentPaymentSchedule::scopeOutstanding().
        $query->where('nature', self::NATURE_PLAN)
            ->whereRaw('amount - settled_amount > '.self::EPSILON);
    }

    /**
     * Непогашенные плановые строки с прошедшей датой.
     *
     * График заказа — план платежа, а не долг (долг создаёт отгрузка), поэтому
     * строки `order` просрочкой не считаются нигде: регистр по срокам заказы
     * не ведёт, погашение по ним не публикуется, и просроченный план заказа
     * оставался бы «долгом» навсегда — круг 12, v16.7.0.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query, ?Carbon $asOf = null): void
    {
        $query->outstanding()
            ->whereDate('date', '<', ($asOf ?? Carbon::today())->toDateString())
            // Отдельным замыканием и с NULL-веткой: голое `<> 'order'` молча
            // выкинуло бы строки без document_kind из просрочки.
            ->where(static function (Builder $query): void {
                $query->whereNull('document_kind')->orWhere('document_kind', '<>', 'order');
            });
    }

    /**
     * Всё, что произошло не позже даты, — срез для акта сверки.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUpTo(Builder $query, Carbon $date): void
    {
        $query->whereDate('date', '<=', $date->toDateString());
    }

    /**
     * Ось акта сверки: контрагент × организация × валюта.
     *
     * Именно этот разрез гарантированно сходится с `balance.updated`. Соглашение
     * в ось не входит намеренно — см. докблок Agreement.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForReconciliation(Builder $query, int $companyId, ?int $organizationId = null, string $currencyCode = 'RUB'): void
    {
        $query->where('company_id', $companyId)->where('currency_code', $currencyCode);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }
    }

    /**
     * Остаток по плановой строке. Переплата остатка не создаёт — отсюда max(0, …):
     * `settled_amount` больше `amount` легитимен, 1С так отражает переплату.
     */
    public function getUnsettledAmountAttribute(): float
    {
        if ($this->nature !== self::NATURE_PLAN) {
            return 0.0;
        }

        return max(0.0, round((float) $this->amount - (float) $this->settled_amount, 2));
    }

    /**
     * Состояние плановой строки: ожидается, частично закрыта, закрыта.
     *
     * Просрочка сюда не входит — это отдельный признак: просроченной бывает
     * и неоплаченная строка, и частично закрытая.
     */
    public function getStatusAttribute(): string
    {
        $settled = (float) $this->settled_amount;

        if ($settled >= (float) $this->amount - self::EPSILON) {
            return self::STATUS_PAID;
        }

        return $settled > self::EPSILON ? self::STATUS_PARTIAL : self::STATUS_PENDING;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'Оплачено',
            self::STATUS_PARTIAL => 'Оплачено частично',
            default => $this->is_overdue ? 'Просрочено' : 'Ожидается',
        };
    }

    /**
     * Плановая дата прошла, а деньги не пришли.
     *
     * Признак вычисляется, а не хранится: хранимый пришлось бы пересчитывать
     * ночным заданием, и он врал бы до следующего запуска.
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->nature !== self::NATURE_PLAN || $this->unsettled_amount <= self::EPSILON) {
            return false;
        }

        // План заказа — не долг, просрочки у него не бывает (см. scopeOverdue).
        if ($this->document_kind === 'order') {
            return false;
        }

        return $this->date instanceof Carbon && $this->date->startOfDay()->isBefore(Carbon::today());
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /**
     * Куда движется долг с точки зрения клиента. Читается в акте сверки рядом
     * с суммой, где голый знак понятен не всем.
     */
    public function getDirectionLabelAttribute(): string
    {
        $amount = (float) $this->amount;

        if (abs($amount) <= self::EPSILON) {
            return 'Без изменения';
        }

        return $amount > 0 ? 'В пользу клиента' : 'В нашу пользу';
    }

    /**
     * Читаемое имя документа-регистратора — по продублированным реквизитам,
     * без обращения к самому документу.
     */
    public function getDocumentLabelAttribute(): string
    {
        $kind = $this->document_kind !== null
            ? (self::DOCUMENT_KIND_LABELS[$this->document_kind] ?? $this->document_kind)
            : 'Документ';

        return $this->document_number !== null
            ? $kind.' '.$this->document_number
            : $kind;
    }
}
