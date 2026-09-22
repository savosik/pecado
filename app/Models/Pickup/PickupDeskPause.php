<?php

namespace App\Models\Pickup;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Живая отлучка со стойки выдачи (pick-18): «отошёл на 20 минут, обед». */
class PickupDeskPause extends Model
{
    protected $fillable = ['staff_id', 'user_id', 'reason', 'started_at', 'until_at', 'ended_at'];

    protected $casts = ['started_at' => 'datetime', 'until_at' => 'datetime', 'ended_at' => 'datetime'];

    /** @return BelongsTo<PickupDeskStaff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PickupDeskStaff::class, 'staff_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ещё действует: не отменена и время не вышло. */
    public function scopeActive(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        return $query->whereNull('ended_at')->where('until_at', '>', $at ?? now());
    }
}
