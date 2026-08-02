<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Попытка отправки предзаказа поставщику (Customer API sex-opt.ru, send_order).
 *
 * @property int $id
 * @property int $order_id
 * @property int $attempt
 * @property string $status
 * @property string $stock
 * @property bool $testmode
 * @property string|null $comment
 * @property array<array-key, mixed>|null $request_payload
 * @property array<array-key, mixed>|null $response_payload
 * @property string|null $response_raw
 * @property int|null $http_status
 * @property string|null $supplier_order_id
 * @property int $items_count
 * @property array<array-key, mixed>|null $skipped_items
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property int|null $triggered_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\User|null $triggeredBy
 *
 * @mixin \Eloquent
 */
class SupplierPreorderRequest extends Model
{
    /** Заказ создан у поставщика (result=success, transaction=commit). */
    public const STATUS_SUCCESS = 'success';

    /** Запрос принят, но транзакция откачена — заказа у поставщика нет. */
    public const STATUS_ROLLBACK = 'rollback';

    /** Тестовая отправка: поставщик всё проверил и откатил транзакцию. */
    public const STATUS_TESTMODE = 'testmode';

    /** Ошибка транспорта или result=error от поставщика. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id',
        'attempt',
        'status',
        'stock',
        'testmode',
        'comment',
        'request_payload',
        'response_payload',
        'response_raw',
        'http_status',
        'supplier_order_id',
        'items_count',
        'skipped_items',
        'error_message',
        'duration_ms',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'testmode' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'skipped_items' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Русская подпись статуса — используется и в админке, и в уведомлениях.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCESS => 'Отправлен',
            self::STATUS_ROLLBACK => 'Откат (не создан)',
            self::STATUS_TESTMODE => 'Тестовый режим',
            default => 'Ошибка',
        };
    }

    /**
     * Предупреждения поставщика: нехватка остатка и неизвестные коды товаров.
     *
     * @return array<string, mixed>
     */
    public function warnings(): array
    {
        $warnings = $this->response_payload['warnings'] ?? [];

        return is_array($warnings) ? $warnings : [];
    }
}
