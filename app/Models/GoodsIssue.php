<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Расходный ордер на товары из 1С (US-20).
 *
 * Складской документ, по которому товар отбирают, проверяют, упаковывают и грузят.
 * Один ордер может собираться сразу по нескольким заказам клиента — связь с заказами
 * живёт в строках (см. {@see GoodsIssueItem}), а не в шапке.
 *
 * Документ read-only: статусами управляет 1С, сайт их только отражает.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property \Illuminate\Support\Carbon|null $date
 * @property \Illuminate\Support\Carbon|null $shipment_date
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $status_changed_at
 * @property string|null $operation
 * @property int|null $warehouse_id
 * @property string|null $warehouse_uuid
 * @property int|null $organization_id
 * @property int|null $company_id
 * @property int|null $user_id
 * @property string|null $contractor_uuid
 * @property string|null $tax_id
 * @property string|null $recipient_name
 * @property string|null $responsible
 * @property string|null $priority
 * @property string|null $comment
 * @property string|null $delivery_type
 * @property string|null $delivery_address
 * @property string|null $delivery_order
 * @property int $packages_count
 * @property int $items_count
 * @property numeric $total_quantity
 * @property int $unresolved_items_count
 * @property \Carbon\Carbon|null $erp_created_at
 * @property \Carbon\Carbon|null $erp_updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read string $status_label
 * @property-read string $status_color
 * @property-read string|null $priority_label
 * @property-read string|null $delivery_type_label
 * @property-read string $recipient_label
 * @property-read \App\Models\Warehouse|null $warehouse
 * @property-read \App\Models\Organization|null $organization
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\User|null $user
 * @property-read bool $is_stale
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\GoodsIssueItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\GoodsIssuePackage> $packages
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\GoodsIssueStatusHistory> $statusHistories
 *
 * @method static \Database\Factories\GoodsIssueFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class GoodsIssue extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_TO_PICK = 'to_pick';

    public const STATUS_TO_CHECK = 'to_check';

    /**
     * «В процессе проверки» — самостоятельное значение перечисления
     * СтатусыРасходныхОрдеров в 1С (четвёртое из семи), а не «К проверке»
     * с признаком работы кладовщика. Добавлено в v15.16.1: до этого 1С
     * схлопывала этап в to_check, и он терялся на экране склада.
     */
    public const STATUS_CHECKING = 'checking';

    public const STATUS_CHECKED = 'checked';

    public const STATUS_TO_SHIP = 'to_ship';

    public const STATUS_SHIPPED = 'shipped';

    /**
     * Статусы в порядке жизненного цикла документа в 1С.
     *
     * Порядок значим: журнал склада показывает статусы фильтром именно в этой
     * последовательности, а не по алфавиту — иначе «Отгружен» оказался бы первым.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PREPARED,
        self::STATUS_TO_PICK,
        self::STATUS_TO_CHECK,
        self::STATUS_CHECKING,
        self::STATUS_CHECKED,
        self::STATUS_TO_SHIP,
        self::STATUS_SHIPPED,
    ];

    /**
     * Метки статусов на русском.
     *
     * Держим на модели, а не в контроллере: их читают журнал, карточка, XLSX-выгрузка
     * и тесты — разъехавшееся написание означало бы, что в выгрузке и на экране
     * один и тот же ордер называется по-разному.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_PREPARED => 'Подготовлен',
        self::STATUS_TO_PICK => 'К отбору',
        self::STATUS_TO_CHECK => 'К проверке',
        self::STATUS_CHECKING => 'В процессе проверки',
        self::STATUS_CHECKED => 'Проверен',
        self::STATUS_TO_SHIP => 'К отгрузке',
        self::STATUS_SHIPPED => 'Отгружен',
    ];

    /** @var array<string, string> */
    public const STATUS_COLORS = [
        self::STATUS_PREPARED => 'gray',
        self::STATUS_TO_PICK => 'blue',
        self::STATUS_TO_CHECK => 'orange',
        self::STATUS_CHECKING => 'yellow',
        self::STATUS_CHECKED => 'purple',
        self::STATUS_TO_SHIP => 'teal',
        self::STATUS_SHIPPED => 'green',
    ];

    /**
     * Статусы, в которых ордер считается «в работе» — товар ещё не уехал.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PREPARED,
        self::STATUS_TO_PICK,
        self::STATUS_TO_CHECK,
        self::STATUS_CHECKING,
        self::STATUS_CHECKED,
        self::STATUS_TO_SHIP,
    ];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    /** @var array<string, string> */
    public const PRIORITY_LABELS = [
        self::PRIORITY_LOW => 'Низкий',
        self::PRIORITY_NORMAL => 'Средний',
        self::PRIORITY_HIGH => 'Высокий',
    ];

    public const DELIVERY_PICKUP = 'pickup';

    public const DELIVERY_DELIVERY = 'delivery';

    /** @var array<string, string> */
    public const DELIVERY_TYPE_LABELS = [
        self::DELIVERY_PICKUP => 'Самовывоз',
        self::DELIVERY_DELIVERY => 'Доставка',
    ];

    /**
     * Сколько часов ордер может простоять в одном статусе, прежде чем считается зависшим.
     *
     * Отгружённые ордера зависшими не бывают — документ закрыт.
     */
    public const STALE_HOURS = 24;

    protected $fillable = [
        'uuid',
        'number',
        'date',
        'shipment_date',
        'status',
        'status_changed_at',
        'operation',
        'warehouse_id',
        'warehouse_uuid',
        'organization_id',
        'company_id',
        'user_id',
        'contractor_uuid',
        'tax_id',
        'recipient_name',
        'responsible',
        'priority',
        'comment',
        'delivery_type',
        'delivery_address',
        'delivery_order',
        'packages_count',
        'items_count',
        'total_quantity',
        'unresolved_items_count',
        'erp_created_at',
        'erp_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'shipment_date' => 'datetime',
            'status_changed_at' => 'datetime',
            'total_quantity' => 'decimal:3',
            'erp_created_at' => \App\Casts\ErpDatetime::class,
            'erp_updated_at' => \App\Casts\ErpDatetime::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $goodsIssue) {
            if (empty($goodsIssue->uuid)) {
                $goodsIssue->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return HasMany<GoodsIssueItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsIssueItem::class)->orderBy('line_number');
    }

    /** @return HasMany<GoodsIssuePackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(GoodsIssuePackage::class)->orderBy('number');
    }

    /** @return HasMany<GoodsIssueStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(GoodsIssueStatusHistory::class)->orderBy('changed_at');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Заказы-распоряжения, по которым собирается ордер.
     *
     * Через строки, а не через шапку: один ордер может собираться сразу по нескольким
     * заказам. Повторяет подход {@see Shipment::getRelatedOrders()}.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Order>
     */
    public function relatedOrders(): \Illuminate\Database\Eloquent\Collection
    {
        $orderUuids = $this->items()
            ->whereNotNull('order_uuid')
            ->pluck('order_uuid')
            ->unique()
            ->values();

        if ($orderUuids->isEmpty()) {
            // Именно Eloquent-коллекция, а не collect(): при объявленном типе возврата
            // обычная Support-коллекция роняет метод TypeError.
            return Order::query()->whereRaw('1 = 0')->get();
        }

        return Order::withoutGlobalScopes()
            ->whereIn('uuid', $orderUuids)
            ->get();
    }

    /**
     * Ордера, застрявшие в текущем статусе дольше `$hours`.
     *
     * Отгружённые не считаются: документ закрыт, время в статусе смысла не имеет.
     */
    /**
     * @param  Builder<GoodsIssue>  $query
     * @return Builder<GoodsIssue>
     */
    public function scopeStale(Builder $query, int $hours = self::STALE_HOURS): Builder
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('status_changed_at')
            ->where('status_changed_at', '<', Carbon::now()->subHours($hours));
    }

    public function getIsStaleAttribute(): bool
    {
        if (! in_array($this->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        if (! $this->status_changed_at instanceof Carbon) {
            return false;
        }

        return $this->status_changed_at->lt(Carbon::now()->subHours(self::STALE_HOURS));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'gray';
    }

    public function getPriorityLabelAttribute(): ?string
    {
        return $this->priority === null
            ? null
            : (self::PRIORITY_LABELS[$this->priority] ?? $this->priority);
    }

    public function getDeliveryTypeLabelAttribute(): ?string
    {
        return $this->delivery_type === null
            ? null
            : (self::DELIVERY_TYPE_LABELS[$this->delivery_type] ?? $this->delivery_type);
    }

    /**
     * Получатель для показа.
     *
     * Приоритет у названия организации с сайта: оно поддерживается в актуальном виде.
     * Строка из 1С — запасной вариант, но именно она спасает ордера на контрагентов,
     * которых в справочниках сайта нет вовсе.
     */
    public function getRecipientLabelAttribute(): string
    {
        return $this->company?->name
            ?? $this->recipient_name
            ?? '—';
    }
}
