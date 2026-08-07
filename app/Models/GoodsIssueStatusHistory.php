<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала смены статусов расходного ордера (US-20).
 *
 * 1С присылает только текущий статус — историю ведёт сайт. По ней склад видит,
 * сколько ордер простоял на каждом этапе.
 *
 * @property int $id
 * @property int $goods_issue_id
 * @property string|null $from_status
 * @property string $to_status
 * @property \Illuminate\Support\Carbon $changed_at
 * @property string $source
 * @property-read string|null $from_status_label
 * @property-read string $to_status_label
 * @property-read \App\Models\GoodsIssue $goodsIssue
 *
 * @mixin \Eloquent
 */
class GoodsIssueStatusHistory extends Model
{
    use HasFactory;

    public const SOURCE_ERP = 'erp';

    /**
     * Псевдостатус отмены проведения документа в 1С.
     *
     * Живёт только в журнале: у самого ордера статус остаётся тем, в котором его
     * застала отмена, — иначе после восстановления документа было бы не понять,
     * на каком этапе он находился.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Метки псевдостатусов, которых нет в жизненном цикле ордера.
     *
     * @var array<string, string>
     */
    public const EXTRA_STATUS_LABELS = [
        self::STATUS_CANCELLED => 'Отменён в 1С',
    ];

    protected $fillable = [
        'goods_issue_id',
        'from_status',
        'to_status',
        'changed_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GoodsIssue, $this> */
    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class);
    }

    public function getFromStatusLabelAttribute(): ?string
    {
        return $this->from_status === null ? null : self::label($this->from_status);
    }

    public function getToStatusLabelAttribute(): string
    {
        return self::label($this->to_status);
    }

    private static function label(string $status): string
    {
        return GoodsIssue::STATUS_LABELS[$status]
            ?? self::EXTRA_STATUS_LABELS[$status]
            ?? $status;
    }
}
