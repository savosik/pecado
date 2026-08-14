<?php

namespace App\Models;

use App\Enums\Substitution\SignalEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сигнал по кандидату замены: копится с первого дня, читается при тюнинге слоёв.
 *
 * @property int $id
 * @property int $offer_item_id
 * @property SignalEvent $event
 */
class SubstitutionEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'offer_item_id',
        'event',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event' => SignalEvent::class,
            'created_at' => 'datetime',
        ];
    }

    public function offerItem(): BelongsTo
    {
        return $this->belongsTo(SubstitutionOfferItem::class, 'offer_item_id');
    }
}
