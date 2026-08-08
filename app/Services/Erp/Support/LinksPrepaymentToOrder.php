<?php

namespace App\Services\Erp\Support;

use App\Models\Order;
use App\Services\Payments\PaymentAllocationService;

/**
 * Пересчёт предоплаты заказа, пришедшего позже платежа (v15.16.0).
 *
 * Прямой аналог LinksPaymentAllocationsToShipment, но для строк расшифровки
 * с `target_type = order`. Причина та же: платежи (`erp_in.payments`) и заказы
 * (`erp_in.orders`) идут разными очередями без гарантии порядка.
 *
 * Для предоплат это не редкий случай, а норма — по замеру 1С предоплата обычно
 * и возникает раньше, чем документ доезжает до сайта.
 *
 * Связь мягкая, по `order_uuid`, поэтому «доклеивать» нечего: строка уже хранит
 * uuid заказа. Пересчитать нужно денормализованный агрегат на самом заказе.
 */
trait LinksPrepaymentToOrder
{
    protected function refreshOrderPrepayment(Order $order): void
    {
        app(PaymentAllocationService::class)->recalculateOrder($order);
    }
}
