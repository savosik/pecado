<?php

namespace App\Models\Motivation;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Партнёр внутри выданного пакета: что с ним произошло и уложились ли в срок.
 *
 * @property int $id
 * @property int $package_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $first_contact_at
 * @property \Illuminate\Support\Carbon|null $first_shipment_at
 * @property \Illuminate\Support\Carbon|null $returned_to_pool_at
 * @property string $outcome
 */
class MotivationPoolPackageItem extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationPoolPackageItemFactory> */
    use HasFactory;

    public const OUTCOME_IN_PROGRESS = 'in_progress';

    public const OUTCOME_CONVERTED = 'converted';

    public const OUTCOME_RETURNED = 'returned';

    protected $fillable = [
        'package_id',
        'user_id',
        'first_contact_at',
        'first_shipment_at',
        'returned_to_pool_at',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'first_contact_at' => 'datetime',
            'first_shipment_at' => 'datetime',
            'returned_to_pool_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MotivationPoolPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(MotivationPoolPackage::class, 'package_id');
    }

    /** @return BelongsTo<User, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
