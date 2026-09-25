<?php

namespace App\Events\Pickup;

use App\Models\GoodsIssue;
use Illuminate\Foundation\Events\Dispatchable;

/** Расходный ордер собран (`ready = true`) либо вернулся в сборку после «отгружен» (`false`). */
class GoodsIssueReadyChanged
{
    use Dispatchable;

    public function __construct(public readonly GoodsIssue $goodsIssue, public readonly bool $ready) {}
}
