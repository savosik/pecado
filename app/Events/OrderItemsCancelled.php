<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 1С отменила строки заказа при сборке (недобор).
 *
 * Диспатчится синхронизатором позиций только по строкам, которые стали
 * отменёнными в этом сообщении шины: повторная доставка того же payload
 * события не порождает. Слушатели получают уже закоммиченное состояние.
 */
class OrderItemsCancelled
{
    use Dispatchable;

    /**
     * @param  list<int>  $orderItemIds  строки заказа, отменённые этим сообщением
     */
    public function __construct(
        public Order $order,
        public array $orderItemIds,
    ) {}
}
