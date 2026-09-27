<?php

namespace App\Observers;

use App\Events\Pickup\GoodsIssueReadyChanged;
use App\Models\GoodsIssue;
use App\Models\GoodsIssueStatusHistory;
use App\Services\Pickup\HandoverService;

/**
 * Самовывоз (pick-06, pick-12): реакция на смену статуса расходного ордера.
 *
 * Слушаем журнал статусов, а не обработчики шины: строка в нём появляется только при
 * фактической смене, и код ERP остаётся нетронутым.
 */
class PickupGoodsIssueStatusObserver
{
    public function __construct(private readonly HandoverService $handovers) {}

    public function created(GoodsIssueStatusHistory $history): void
    {
        $wasShipped = $history->from_status === GoodsIssue::STATUS_SHIPPED;
        $isShipped = $history->to_status === GoodsIssue::STATUS_SHIPPED;

        if ($wasShipped === $isShipped) {
            return;
        }

        $goodsIssue = GoodsIssue::withTrashed()->find($history->goods_issue_id);
        if ($goodsIssue === null) {
            return;
        }

        // «Отгружен» обратим: если ордер уже выдан, выдачу не удаляем, а поднимаем на разбор.
        if ($wasShipped) {
            $this->handovers->flagRollback($goodsIssue, (string) $history->to_status);
        }

        if (config('pickup.enabled')) {
            event(new GoodsIssueReadyChanged($goodsIssue, $isShipped));
        }
    }
}
