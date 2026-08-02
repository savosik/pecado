<?php

namespace App\Models;

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
    use HasCrmAttachments, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'erp_number',
        'number',
        'user_id',
        'company_id',
        'organization_id',
        'warehouse_id',
        'tax_id',
        'date',
        'status',
        'currency_code',
        'total_amount',
        'erp_created_at',
        'erp_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_amount' => 'decimal:2',
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
