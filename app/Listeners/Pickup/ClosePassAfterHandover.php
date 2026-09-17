<?php

namespace App\Listeners\Pickup;

use App\Events\Pickup\GoodsIssueHandedOver;
use App\Services\Pickup\PickupPassService;

/** Пропуск, по которому больше нечего выдавать, становится использованным (pick-09). */
class ClosePassAfterHandover
{
    public function __construct(private readonly PickupPassService $passes) {}

    public function handle(GoodsIssueHandedOver $event): void
    {
        $pass = $event->handover->pass;
        if ($pass !== null) {
            $this->passes->closeIfComplete($pass);
        }
    }
}
