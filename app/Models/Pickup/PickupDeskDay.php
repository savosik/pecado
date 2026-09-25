<?php

namespace App\Models\Pickup;

use Illuminate\Database\Eloquent\Model;

/**
 * День недели стойки выдачи (pick-18): работает ли, часы и технические перерывы.
 *
 * Строка = то, что видят курьер и клиент. Часы пустые — берутся из config/warehouse.php.
 */
class PickupDeskDay extends Model
{
    protected $fillable = ['iso_weekday', 'works', 'opens_at', 'closes_at', 'breaks'];

    protected $casts = ['works' => 'boolean', 'breaks' => 'array'];

    /** «13:00» из значения колонки time. */
    public static function hm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
