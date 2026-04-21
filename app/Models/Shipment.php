<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Shipment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'erp_number',
        'number',
        'user_id',
        'company_id',
        'tax_id',
        'date',
        'status',
        'currency_code',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_amount' => 'decimal:2',
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
            return collect();
        }

        return Order::withoutGlobalScopes()
            ->whereIn('uuid', $orderUuids)
            ->get();
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
