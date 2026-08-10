<?php

namespace App\Models\Delivery;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала HTTP-вызовов ApiShip.
 *
 * @property int $id
 * @property int|null $delivery_shipment_id
 * @property string $operation
 * @property string $method
 * @property string $endpoint
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $response_payload
 * @property string|null $response_raw
 * @property int|null $http_status
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property int|null $triggered_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class ApiShipRequest extends Model
{
    use HasFactory;

    /**
     * Явно: из имени класса Laravel вывел бы `api_ship_requests` — с лишним
     * подчёркиванием, которого в схеме нет.
     */
    protected $table = 'apiship_requests';

    public const OPERATION_LOGIN = 'login';

    public const OPERATION_CALCULATOR = 'calculator';

    public const OPERATION_CREATE_ORDER = 'create_order';

    public const OPERATION_GET_ORDER = 'get_order';

    public const OPERATION_CANCEL_ORDER = 'cancel_order';

    public const OPERATION_STATUSES_INTERVAL = 'statuses_interval';

    public const OPERATION_POINTS = 'points';

    public const OPERATION_DOCUMENT = 'document';

    public const OPERATION_COURIER = 'courier';

    public const OPERATION_WEBHOOK = 'webhook_subscribe';

    /** @var list<string> */
    protected $fillable = [
        'delivery_shipment_id',
        'operation',
        'method',
        'endpoint',
        'request_payload',
        'response_payload',
        'response_raw',
        'http_status',
        'error_message',
        'duration_ms',
        'triggered_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<DeliveryShipment, $this> */
    public function deliveryShipment(): BelongsTo
    {
        return $this->belongsTo(DeliveryShipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isSuccessful(): bool
    {
        return $this->error_message === null
            && $this->http_status !== null
            && $this->http_status >= 200
            && $this->http_status < 300;
    }
}
