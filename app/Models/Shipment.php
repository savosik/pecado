<?php

namespace App\Models;

use App\Models\Concerns\FiltersClientDocuments;
use App\Models\Concerns\HasCrmAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $number Локальный или комбинированный номер (если нужен)
 * @property string|null $erp_number Номер реализации в 1С
 * @property int|null $user_id
 * @property int|null $company_id
 * @property string|null $tax_id
 * @property \Illuminate\Support\Carbon|null $date
 * @property string $status
 * @property string|null $currency_code
 * @property numeric $total_amount
 * @property numeric $paid_amount
 * @property string $payment_status
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $payment_due_date
 * @property-read string $payment_status_label
 * @property-read float $unpaid_amount
 * @property-read bool $is_payment_overdue
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ShipmentPaymentSchedule> $paymentSchedules
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $erp_created_at
 * @property \Carbon\Carbon|null $erp_updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Company|null $company
 * @property-read string $status_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ShipmentItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User|null $user
 *
 * @method static \Database\Factories\ShipmentFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereCurrencyCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereErpCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereErpNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereErpUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereTaxId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Shipment withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Shipment extends Model implements HasMedia
{
    use FiltersClientDocuments, HasCrmAttachments, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'erp_number',
        'number',
        // v15.16.0: реквизиты счёта-фактуры из 1С — для бухгалтерии клиента.
        // v15.16.1: печатный номер отдельно от внутреннего — клиент сверяет по бумаге
        'invoice_number',
        'invoice_number_display',
        'invoice_date',
        'user_id',
        'company_id',
        'organization_id',
        'warehouse_id',
        'tax_id',
        'date',
        'status',
        'currency_code',
        'total_amount',
        'paid_amount',
        'payment_status',
        'paid_at',
        'payment_due_date',
        'erp_created_at',
        'erp_updated_at',
    ];

    /**
     * Статусы оплаты реализации.
     *
     * Держим константами на модели, чтобы CRM, кабинет и экспорт не разъехались
     * в написании: это производное поле, его значения задаёт только PaymentAllocationService.
     */
    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_OVERPAID = 'overpaid';

    /** @var list<string> */
    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_PARTIAL,
        self::PAYMENT_PAID,
        self::PAYMENT_OVERPAID,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'invoice_date' => 'date',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'payment_due_date' => 'date',
            'erp_created_at' => \App\Casts\ErpDatetime::class,
            'erp_updated_at' => \App\Casts\ErpDatetime::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($shipment) {
            if (empty($shipment->uuid)) {
                $shipment->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Наша организация (юрлицо), от имени которой 1С провела реализацию.
     *
     * Может отличаться от организации заказа: 1С могла переоформить документ.
     * Это легитимно, сайт расхождение не «чинит».
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Склад отгрузки, определённый 1С.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * Все уникальные заказы, связанные с этой реализацией через позиции.
     * Выбираем заказы по order_uuid из items.
     */
    public function getRelatedOrders(): \Illuminate\Database\Eloquent\Collection
    {
        $orderUuids = $this->items()
            ->whereNotNull('order_uuid')
            ->pluck('order_uuid')
            ->unique()
            ->values();

        if ($orderUuids->isEmpty()) {
            // Именно Eloquent-коллекция, а не collect(): при объявленном типе возврата
            // обычная Support-коллекция роняла метод TypeError на каждой реализации
            // без привязки к заказу.
            return Order::query()->whereRaw('1 = 0')->get();
        }

        return Order::withoutGlobalScopes()
            ->whereIn('uuid', $orderUuids)
            ->get();
    }

    /**
     * Строки расшифровки платежей, разнесённых на эту реализацию.
     *
     * @return HasMany<\App\Models\PaymentAllocation, $this>
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Платежи, которыми закрывается реализация.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Payment, $this>
     */
    public function payments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations')
            ->withPivot(['amount', 'order_uuid', 'line_number'])
            ->withTimestamps();
    }

    /**
     * График оплаты — плановые даты и суммы из «Правил оплаты» 1С (v15.12.0).
     *
     * Отдаётся сразу в порядке погашения: строки читают и календарь, и карточка
     * документа, и FIFO-раскладка — разъехавшийся порядок означал бы, что клиент
     * видит одну очерёдность, а закрывается другая.
     *
     * @return HasMany<\App\Models\ShipmentPaymentSchedule, $this>
     */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(ShipmentPaymentSchedule::class)->inFifoOrder();
    }

    /**
     * По ближайшей плановой дате уже просрочено.
     *
     * Считается по денормализованной `payment_due_date`, поэтому доступно
     * и без загрузки строк графика — списки и экспорт этим и пользуются.
     */
    public function getIsPaymentOverdueAttribute(): bool
    {
        if (! $this->payment_due_date instanceof \Illuminate\Support\Carbon) {
            return false;
        }

        return $this->payment_due_date->startOfDay()->isBefore(\Illuminate\Support\Carbon::today());
    }

    /**
     * Метка статуса оплаты на русском.
     */
    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->payment_status) {
            self::PAYMENT_PAID => 'Оплачена',
            self::PAYMENT_PARTIAL => 'Оплачена частично',
            self::PAYMENT_OVERPAID => 'Переплата',
            default => 'Не оплачена',
        };
    }

    /**
     * Остаток к оплате. Переплата остатка не создаёт — отсюда max(0, …).
     */
    public function getUnpaidAmountAttribute(): float
    {
        return max(0.0, round((float) $this->total_amount - (float) $this->paid_amount, 2));
    }

    /**
     * Метка статуса на русском языке.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'completed' => 'Выполнена',
            'in_progress' => 'В обработке',
            'new' => 'Новая',
            'cancelled' => 'Отменена',
            default => $this->status,
        };
    }
}
