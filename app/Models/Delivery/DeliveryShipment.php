<?php

namespace App\Models\Delivery;

use App\Enums\Delivery\ApiShipStatus;
use App\Enums\Delivery\DeliveryShipmentStatus;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Отправка груза транспортной компанией через агрегатор ApiShip.
 *
 * @property int $id
 * @property string|null $number
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $warehouse_id
 * @property DeliveryShipmentStatus $status
 * @property bool $is_manual
 * @property string|null $carrier_name
 * @property string|null $provider_key
 * @property int|null $tariff_id
 * @property string|null $tariff_name
 * @property int $delivery_type
 * @property int $pickup_type
 * @property string|null $point_id
 * @property string|null $point_address
 * @property string|null $apiship_order_id
 * @property string|null $provider_number
 * @property string|null $barcode
 * @property string|null $tracking_url
 * @property string|null $apiship_status_key
 * @property string|null $apiship_status_name
 * @property \Illuminate\Support\Carbon|null $apiship_status_at
 * @property int $calculated_weight
 * @property int|null $declared_weight
 * @property int $places_count
 * @property numeric $assessed_cost
 * @property numeric|null $delivery_cost
 * @property numeric|null $delivery_cost_original
 * @property \Illuminate\Support\Carbon|null $pickup_date
 * @property array<string, mixed>|null $sender
 * @property array<string, mixed>|null $recipient
 * @property string|null $recipient_city
 * @property string|null $recipient_contact
 * @property string|null $comment
 * @property string|null $last_error
 * @property int|null $created_by
 * @property int|null $submitted_by
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int $effective_weight
 * @property-read string $status_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DeliveryShipmentPlace> $places
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DeliveryShipmentStatusHistory> $statusHistories
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Shipment> $shipments
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static \Illuminate\Database\Eloquent\Builder<static> trackable()
 *
 * @mixin \Eloquent
 */
class DeliveryShipment extends Model
{
    use HasFactory, SoftDeletes;

    /** Префикс собственного номера отправки. Он же уезжает в ApiShip как clientNumber. */
    public const NUMBER_PREFIX = 'DS-';

    /** Доставка до двери получателя (ApiShip deliveryType). */
    public const DELIVERY_TYPE_DOOR = 1;

    /** Доставка до пункта выдачи (ApiShip deliveryType). */
    public const DELIVERY_TYPE_POINT = 2;

    /** Забор груза курьером со склада (ApiShip pickupType). */
    public const PICKUP_TYPE_COURIER = 1;

    /** Груз сдаём на терминал перевозчика сами (ApiShip pickupType). */
    public const PICKUP_TYPE_SELF = 2;

    /** @var list<string> */
    protected $fillable = [
        'number',
        'user_id',
        'company_id',
        'warehouse_id',
        'status',
        'is_manual',
        'provider_key',
        'carrier_name',
        'tariff_id',
        'tariff_name',
        'delivery_type',
        'pickup_type',
        'point_id',
        'point_address',
        'apiship_order_id',
        'provider_number',
        'barcode',
        'tracking_url',
        'apiship_status_key',
        'apiship_status_name',
        'apiship_status_at',
        'calculated_weight',
        'declared_weight',
        'places_count',
        'assessed_cost',
        'delivery_cost',
        'delivery_cost_original',
        'pickup_date',
        'sender',
        'recipient',
        'recipient_city',
        'recipient_contact',
        'comment',
        'last_error',
        'created_by',
        'submitted_by',
        'submitted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryShipmentStatus::class,
            'is_manual' => 'boolean',
            'sender' => 'array',
            'recipient' => 'array',
            'pickup_date' => 'date',
            'apiship_status_at' => 'datetime',
            'submitted_at' => 'datetime',
            'assessed_cost' => 'decimal:2',
            'delivery_cost' => 'decimal:2',
            'delivery_cost_original' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Номер присваиваем после вставки, а не до: он строится из id, и любой
        // расчёт «максимум + 1» заранее ловил бы гонку при двух кладовщиках сразу.
        static::created(function (DeliveryShipment $shipment): void {
            if ($shipment->number === null || $shipment->number === '') {
                $shipment->forceFill([
                    'number' => self::NUMBER_PREFIX.str_pad((string) $shipment->id, 6, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        });
    }

    // ─────────────────────────── Связи ───────────────────────────

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

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Реализации из 1С, вошедшие в отправку.
     *
     * @return BelongsToMany<Shipment, $this>
     */
    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'delivery_shipment_documents')
            ->withPivot(['amount', 'weight'])
            ->withTimestamps();
    }

    /** @return HasMany<DeliveryShipmentPlace, $this> */
    public function places(): HasMany
    {
        return $this->hasMany(DeliveryShipmentPlace::class)->orderBy('number');
    }

    /** @return HasMany<DeliveryShipmentStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(DeliveryShipmentStatusHistory::class)->orderByDesc('occurred_at');
    }

    /** @return HasMany<ApiShipRequest, $this> */
    public function apiShipRequests(): HasMany
    {
        return $this->hasMany(ApiShipRequest::class)->orderByDesc('id');
    }

    // ─────────────────────────── Скоупы ───────────────────────────

    /**
     * Отправки, по которым перевозчик ещё может прислать статус.
     *
     * @param  Builder<self>  $query
     */
    public function scopeTrackable(Builder $query): void
    {
        $query->whereNotNull('apiship_order_id')
            ->whereIn('status', [
                DeliveryShipmentStatus::SUBMITTING->value,
                DeliveryShipmentStatus::SUBMITTED->value,
                DeliveryShipmentStatus::IN_TRANSIT->value,
            ]);
    }

    // ─────────────────────────── Атрибуты ───────────────────────────

    /**
     * Ссылка отслеживания всегда с протоколом.
     *
     * Перевозчики присылают её как придётся: DPD, например, отдаёт `dpd.ru/t?xxx`
     * без схемы. Браузер такую ссылку считает относительной и уводит на 404 внутри
     * нашего же домена, поэтому чиним на записи — писателей у поля трое (вебхук,
     * сверка, ручная отметка), и договариваться с каждым дороже.
     */
    public function setTrackingUrlAttribute(?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            $this->attributes['tracking_url'] = null;

            return;
        }

        $this->attributes['tracking_url'] = preg_match('~^https?://~i', $value) === 1
            ? $value
            : 'https://'.ltrim($value, '/');
    }

    /**
     * Вес, который уходит перевозчику: фактический, если кладовщик взвесил груз,
     * иначе расчётный по товарам.
     */
    public function getEffectiveWeightAttribute(): int
    {
        return (int) ($this->declared_weight ?: $this->calculated_weight);
    }

    /**
     * Статус у перевозчика как енум. NULL, пока заявка не ушла;
     * незнакомый ключ трактуем как UNKNOWN, но исходное значение остаётся в колонке.
     */
    public function apiShipStatus(): ?ApiShipStatus
    {
        if ($this->apiship_status_key === null) {
            return null;
        }

        return ApiShipStatus::tryFrom($this->apiship_status_key) ?? ApiShipStatus::UNKNOWN;
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    public function getProviderStatusLabelAttribute(): ?string
    {
        // Название от перевозчика точнее нашего справочника — оно и приоритетнее.
        return $this->apiship_status_name ?: $this->apiShipStatus()?->label();
    }

    /**
     * Человекочитаемое имя перевозчика: у ручных отправок оно единственное,
     * у остальных дублирует код службы из ApiShip.
     */
    public function getCarrierLabelAttribute(): ?string
    {
        return $this->carrier_name ?: $this->provider_key;
    }

    public function isDoorDelivery(): bool
    {
        return (int) $this->delivery_type === self::DELIVERY_TYPE_DOOR;
    }
}
