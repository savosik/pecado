<?php

namespace App\Models\Delivery;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Реализация, убранная складом из списка кандидатов на отправку.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int|null $hidden_by
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class HiddenShipment extends Model
{
    use HasFactory;

    protected $table = 'delivery_hidden_shipments';

    /** @var list<string> */
    protected $fillable = ['shipment_id', 'hidden_by', 'reason'];

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }
}
