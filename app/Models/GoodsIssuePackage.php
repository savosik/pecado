<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Упаковочный лист (место) расходного ордера — вкладка «Отгружаемые товары» (US-20).
 *
 * @property int $id
 * @property int $goods_issue_id
 * @property int $number
 * @property int|null $positions_count
 * @property numeric|null $weight
 * @property numeric|null $volume
 * @property-read \App\Models\GoodsIssue $goodsIssue
 *
 * @mixin \Eloquent
 */
class GoodsIssuePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_issue_id',
        'number',
        'positions_count',
        'weight',
        'volume',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'volume' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<GoodsIssue, $this> */
    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class);
    }
}
