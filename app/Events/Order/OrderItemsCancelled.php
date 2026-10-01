<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 1С отменила строки заказа (недобор). Одно событие на сообщение шины.
 *
 * Причина отмены — в `reason` каждой строки (значение {@see \App\Enums\Order\OrderLineCancelReason}
 * либо null, если 1С её не передала: сообщения до v16.17.0). Строку снимает не только склад —
 * клиент уменьшает состав резерва сам, резерв истекает по сроку, — поэтому слушатели сами
 * решают по причине, считать ли отмену нехваткой товара.
 */
class OrderItemsCancelled
{
    use Dispatchable;

    /** @param list<array{name: string, quantity: float, reason?: string|null}> $items */
    public function __construct(public readonly Order $order, public readonly array $items) {}
}
