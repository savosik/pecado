<?php

namespace App\Models;

use App\Enums\OrderStatus;
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
 * @property string|null $number
 * @property string|null $erp_number Номер заказа в 1С
 * @property int|null $user_id
 * @property int|null $company_id
 * @property string|null $delivery_address
 * @property \App\Enums\DeliveryMethod $delivery_method
 * @property int|null $cart_id
 * @property OrderStatus $status
 * @property string|null $comment
 * @property string|null $manager_comment
 * @property string|null $warehouse_comment
 * @property numeric $total_amount
 * @property numeric $exchange_rate
 * @property numeric $rate_coefficient
 * @property string|null $currency_code
 * @property int|null $parent_id
 * @property \App\Enums\OrderType $type
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $erp_created_at
 * @property \Carbon\Carbon|null $erp_updated_at
 * @property-read \App\Models\Cart|null $cart
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderChangeLog> $changeLogs
 * @property-read int|null $change_logs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $children
 * @property-read int|null $children_count
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read int|null $items_count
 * @property-read Order|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReturnItem> $returnItems
 * @property-read int|null $return_items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Shipment> $shipments
 * @property-read int|null $shipments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderStatusHistory> $statusHistories
 * @property-read int|null $status_histories_count
 * @property-read \App\Models\User|null $user
 *
 * @method static \Database\Factories\OrderFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCartId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCurrencyCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDeliveryAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereErpCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereErpNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereErpUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereExchangeRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereRateCoefficient($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order withShipmentsCount()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Order extends Model implements HasMedia
{
    use HasCrmAttachments, HasFactory, SoftDeletes;

    /** Флаг: заказ обновляется из ERP — не публиковать обратно в шину */
    public bool $fromErp = false;

    /**
     * Предыдущий статус заказа (заполняется в `updating` hook перед сменой).
     * Используется mail-listener-ом, чтобы сформулировать «было → стало» в письме клиенту.
     */
    public ?OrderStatus $previousStatus = null;

    protected $dispatchesEvents = [
        'updated' => \App\Events\OrderUpdated::class,
        'deleted' => \App\Events\OrderDeleted::class,
    ];

    protected $fillable = [
        'uuid',
        'number',
        'erp_number',
        'user_id',
        'company_id',
        'organization_id',
        'warehouse_id',
        'cart_id',
        'checkout_uuid',
        'status',
        'comment',
        'manager_comment',
        'warehouse_comment',
        'delivery_address',
        'delivery_method',
        'total_amount',
        'exchange_rate',
        'rate_coefficient',
        'currency_code',
        'parent_id',
        'type',
        'erp_created_at',
        'erp_updated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'rate_coefficient' => 'decimal:4',
            'type' => \App\Enums\OrderType::class,
            'delivery_method' => \App\Enums\DeliveryMethod::class,
            'erp_created_at' => \App\Casts\ErpDatetime::class,
            'erp_updated_at' => \App\Casts\ErpDatetime::class,
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function ($order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
            if (empty($order->number)) {
                $order->number = 'ORD-'.now()->format('Y').'-'.str_pad(
                    (string) (static::withTrashed()->max('id') + 1),
                    4,
                    '0',
                    STR_PAD_LEFT
                );
            }
        });

        // Автоматическая запись истории при создании заказа
        static::created(function (Order $order) {
            $order->statusHistories()->create([
                'old_status' => null,
                'new_status' => $order->status,
                'user_id' => auth()->id(),
                'comment' => request()->input('status_comment'),
            ]);
        });

        // Автоматическая запись истории при изменении статуса
        static::updating(function (Order $order) {
            if ($order->isDirty('status')) {
                $original = $order->getOriginal('status');
                $order->previousStatus = $original instanceof OrderStatus
                    ? $original
                    : (is_string($original) ? OrderStatus::tryFrom($original) : null);

                $order->statusHistories()->create([
                    'old_status' => $original,
                    'new_status' => $order->status,
                    'user_id' => auth()->id(),
                    'comment' => request()->input('status_comment'),
                ]);
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
     * Наша организация (юрлицо), на которую 1С провела заказ.
     *
     * Заполняет только 1С — сайт организацию не определяет. NULL значит
     * «не указана»: 1С ещё не умеет её присылать либо заказ создан до v15.8.0.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Склад отгрузки, определённый 1С.
     *
     * Не путать со складами оформления: сайт отправляет перечисление складов
     * региона, а конкретный выбирает 1С и возвращает обратно.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_id');
    }

    /**
     * Get the return items for this order.
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    /**
     * Get the status history for this order.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at', 'desc');
    }

    /**
     * Журнал изменений заказа (позиции, скидки и т.д.)
     */
    public function changeLogs(): HasMany
    {
        return $this->hasMany(OrderChangeLog::class)->orderBy('created_at', 'desc');
    }

    /**
     * Реализации (отгрузки) по этому заказу — через shipment_items.order_uuid.
     */
    public function shipments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Shipment::class,
            'shipment_items',
            'order_uuid',
            'shipment_id',
            'uuid',
            'id'
        )->distinct();
    }

    /**
     * Количество уникальных отгрузок (документов) для заказа.
     * Заменяет withCount('shipments'), который через pivot shipment_items
     * считает строки позиций, а не отдельные отгрузки.
     */
    public function scopeWithShipmentsCount($query)
    {
        return $query->addSelect([
            'shipments_count' => Shipment::query()
                ->selectRaw('COUNT(DISTINCT shipments.id)')
                ->join('shipment_items', 'shipment_items.shipment_id', '=', 'shipments.id')
                ->whereColumn('shipment_items.order_uuid', 'orders.uuid'),
        ]);
    }
}
