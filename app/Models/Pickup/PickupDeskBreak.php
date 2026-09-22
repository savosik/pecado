<?php

namespace App\Models\Pickup;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Плановый технический перерыв сотрудника стойки выдачи (pick-18). */
class PickupDeskBreak extends Model
{
    protected $fillable = ['staff_id', 'weekdays', 'starts_at', 'ends_at', 'label', 'active'];

    protected $casts = ['weekdays' => 'array', 'active' => 'boolean'];

    /** @return BelongsTo<PickupDeskStaff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PickupDeskStaff::class, 'staff_id');
    }

    public function appliesOn(int $isoWeekday): bool
    {
        return $this->active && in_array($isoWeekday, array_map('intval', (array) $this->weekdays), true);
    }

    /** «13:00» из значения колонки time. */
    public static function hm(?string $time): string
    {
        return substr((string) $time, 0, 5);
    }
}
