<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Закупки партнёра у конкурента по инсайдерской выгрузке (агрегат за окно).
 *
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property string $competitor_partner
 * @property string $amount
 * @property int $documents
 * @property CarbonImmutable|null $first_purchase_on
 * @property CarbonImmutable|null $last_purchase_on
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property CarbonImmutable $imported_at
 */
class CrmCompetitorPurchase extends Model
{
    public const SOURCE_ANDREY = 'andrey';

    protected $fillable = [
        'user_id', 'source', 'competitor_partner', 'amount', 'documents',
        'first_purchase_on', 'last_purchase_on', 'period_from', 'period_to', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'documents' => 'integer',
            'first_purchase_on' => 'immutable_date',
            'last_purchase_on' => 'immutable_date',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
