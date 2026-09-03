<?php

namespace App\Services\Erp;

use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Публикатор событий окна резерва (Сайт → 1С), v16.9.0.
 *
 * Режим «Заказы в резерве» (топик №5 Agent Hub, resolution 03.09.2026):
 * правка состава, отмена и подтверждение заказа — действия КЛИЕНТА (кабинет,
 * клиентский API) или таймера сайта, поэтому публикуются явно из этих действий,
 * а не из модельных событий Eloquent. Это и есть эхо-защита по построению:
 * обработчики входящих сообщений 1С никогда не вызывают этот сервис.
 *
 * Все payload-ы валидируются по JSON Schema в PublishOrderToErpJob перед
 * отправкой в erp_out.orders; невалидное сообщение в шину не уходит и попадает
 * в журнал ошибок валидации.
 */
class OrderReservePublisher
{
    /** Причина отмены: клиент отменил заказ сам (кабинет или клиентский API). */
    public const REASON_CLIENT_CANCELLED = 'client_cancelled';

    /** Причина отмены: сайт снял резерв по истечении reserved_until. */
    public const REASON_RESERVE_EXPIRED = 'reserve_expired';

    /**
     * Правка состава резервного заказа клиентом.
     *
     * Состав уходит ЦЕЛИКОМ (полная замена табличной части в 1С). В v1 сайт
     * гарантирует только уменьшение: снизить количество, удалить строку.
     *
     * @param  int  $baseItemsVersion  Версия состава, от которой клиент правил, —
     *                                 последний items_version, применённый сайтом
     *                                 из сообщений 1С (оптимистичная блокировка).
     */
    public function publishUpdated(Order $order, int $baseItemsVersion): void
    {
        $order->loadMissing('items.product');

        $payload = [
            'event' => 'order.updated',
            'message_id' => $this->newMessageId(),
            'uuid' => $order->uuid,
            'base_items_version' => $baseItemsVersion,
            'items' => $order->items->values()->map(function ($item, $index) {
                return [
                    'line_number' => $item->line_number ?? $index + 1,
                    'product_uuid' => $item->product?->external_id,
                    'quantity' => $item->quantity,
                    'base_price' => (float) ($item->base_price ?? $item->price),
                    'discount_percent' => (float) ($item->discount_percent ?? 0),
                    'final_price' => (float) ($item->final_price ?? $item->price),
                ];
            })->toArray(),
            'timestamp' => now()->toIso8601String(),
        ];

        PublishOrderToErpJob::dispatch($payload);
    }

    /**
     * Отмена заказа: клиентом (REASON_CLIENT_CANCELLED) или таймером сайта
     * по истечении резерва (REASON_RESERVE_EXPIRED).
     *
     * В 1С: строки помечаются отменёнными с причиной, статус «Закрыт»,
     * резерв освобождается; пометка удаления не ставится.
     */
    public function publishDeleted(Order $order, string $reason): void
    {
        $payload = [
            'event' => 'order.deleted',
            'message_id' => $this->newMessageId(),
            'uuid' => $order->uuid,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
        ];

        PublishOrderToErpJob::dispatch($payload);
    }

    /**
     * Подтверждение резервного заказа — «отправить в отгрузку».
     *
     * В 1С: строки «Резервировать на складе» → «Отгрузить», статус «К отгрузке»,
     * дальше обычный конвейер. Идемпотентно на стороне 1С.
     */
    public function publishConfirmed(Order $order, ?CarbonInterface $confirmedAt = null): void
    {
        $payload = [
            'event' => 'order.confirmed',
            'message_id' => $this->newMessageId(),
            'uuid' => $order->uuid,
            'confirmed_at' => ($confirmedAt ?? now())->toIso8601String(),
            'timestamp' => now()->toIso8601String(),
        ];

        PublishOrderToErpJob::dispatch($payload);
    }

    private function newMessageId(): string
    {
        return 'msg-'.Str::uuid()->toString();
    }
}
