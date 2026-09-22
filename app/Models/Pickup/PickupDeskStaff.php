<?php

namespace App\Models\Pickup;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Сотрудник стойки выдачи (pick-18): в какие дни недели выдаёт заказы и когда у него перерывы. */
class PickupDeskStaff extends Model
{
    protected $table = 'pickup_desk_staff';

    protected $fillable = ['name', 'user_id', 'weekdays', 'active', 'sort_order'];

    protected $casts = ['weekdays' => 'array', 'active' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PickupDeskBreak, $this> */
    public function breaks(): HasMany
    {
        return $this->hasMany(PickupDeskBreak::class, 'staff_id')->orderBy('starts_at');
    }

    public function worksOn(int $isoWeekday): bool
    {
        return in_array($isoWeekday, array_map('intval', (array) $this->weekdays), true);
    }
}
