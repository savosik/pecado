<?php

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Оформление завершено: клиент сделал одну покупку.
 *
 * Отличается от `OrderCreated` тем, что описывает **покупку**, а не документ.
 * Корзина расщепляется по типам и даёт до пяти заказов (наличие, предзаказ,
 * уценка, промо, рекламные образцы), а письмо клиенту должно быть одно.
 *
 * `OrderCreated` остаётся: он про документ и нужен обмену с 1С, где каждый
 * заказ уезжает отдельным сообщением. Здесь — про то, что видит человек.
 */
class OrdersPlaced
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  Collection<int, Order>  $orders  все документы одного оформления
     */
    public function __construct(
        public Collection $orders,
        public ?User $user = null,
    ) {
        $this->user ??= $orders->first()?->user;
    }
}
