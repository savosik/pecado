<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 1С отменила строки заказа (недобор). Одно событие на сообщение шины.
 *
 * Причину 1С не передаёт; в окне резерва строки уменьшает сам клиент, поэтому слушатели
 * сами решают, считать ли отмену недобором склада.
 */
class OrderItemsCancelled
{
    use Dispatchable;

    /** @param list<array{name: string, quantity: float}> $items */
    public function __construct(public readonly Order $order, public readonly array $items) {}
}
