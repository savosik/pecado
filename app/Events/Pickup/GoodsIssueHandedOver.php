<?php

namespace App\Events\Pickup;

use App\Models\Pickup\PickupHandover;
use Illuminate\Foundation\Events\Dispatchable;

/** Расходный ордер выдан курьеру (pick-06). Слушают: закрытие пропуска, уведомление клиенту. */
class GoodsIssueHandedOver
{
    use Dispatchable;

    public function __construct(public readonly PickupHandover $handover) {}
}
