<?php

namespace App\Models\Pickup;

use App\Models\GoodsIssue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Строка пропуска с охватом selected: расходный ордер, который разрешено выдать. */
class PickupPassItem extends Model
{
    protected $fillable = ['pickup_pass_id', 'goods_issue_id'];

    /** @return BelongsTo<GoodsIssue, $this> */
    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class)->withTrashed();
    }
}
