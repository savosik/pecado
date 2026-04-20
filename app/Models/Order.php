<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    /** Флаг: заказ обновляется из ERP — не публиковать обратно в шину */
    public bool $fromErp = false;

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
        'cart_id',
        'status',
        'comment',
        'delivery_address',
        'total_amount',
        'exchange_rate',
        'rate_coefficient',
        'currency_code',
        'parent_id',
        'type',
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

        // Автоматическая запись истории при изменении статуса
        static::updating(function (Order $order) {
            if ($order->isDirty('status')) {
                $order->statusHistories()->create([
                    'old_status' => $order->getOriginal('status'),
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
}
