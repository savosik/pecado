<?php

namespace App\Models\Pickup;

use App\Models\GoodsIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт выдачи расходного ордера курьеру (pick-06). Документ сайта: 1С о нём не знает.
 *
 * @property int $id
 * @property int $goods_issue_id
 * @property ?int $pickup_pass_id
 * @property ?int $issued_by
 * @property \Illuminate\Support\Carbon $issued_at
 * @property string $method
 * @property ?\Illuminate\Support\Carbon $cancelled_at
 * @property bool $needs_review
 */
class PickupHandover extends Model
{
    public const METHOD_QR = 'qr';

    public const METHOD_CODE = 'code';

    public const METHOD_BARCODE = 'barcode';

    public const METHOD_MANUAL = 'manual';

    public const METHOD_BACKFILL = 'backfill';

    public const METHOD_LABELS = [
        self::METHOD_QR => 'QR пропуска',
        self::METHOD_CODE => 'Код пропуска',
        self::METHOD_BARCODE => 'Скан расходного листа',
        self::METHOD_MANUAL => 'Вручную',
        self::METHOD_BACKFILL => 'Закрыто без выдачи',
    ];

    protected $fillable = [
        'goods_issue_id', 'pickup_pass_id', 'issued_by', 'issued_at', 'method', 'recipient_name',
        'packages_count', 'box_verified', 'comment', 'cancelled_at', 'cancelled_by', 'cancel_reason',
        'needs_review', 'review_note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'box_verified' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    /** @return BelongsTo<GoodsIssue, $this> */
    public function goodsIssue(): BelongsTo
    {
        return $this->belongsTo(GoodsIssue::class)->withTrashed();
    }

    /** @return BelongsTo<PickupPass, $this> */
    public function pass(): BelongsTo
    {
        return $this->belongsTo(PickupPass::class, 'pickup_pass_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<PickupHandover>  $query
     * @return Builder<PickupHandover>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function getMethodLabelAttribute(): string
    {
        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }
}
