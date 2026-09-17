<?php

namespace App\Models\Pickup;

use Illuminate\Database\Eloquent\Model;

/** Нераспознанный скан на экране выдачи (pick-08): сырьё для разбора формата штрихкода 1С. */
class PickupScanMiss extends Model
{
    public const UPDATED_AT = null;

    public const KIND_SCAN = 'scan';

    public const KIND_CODE = 'code';

    protected $fillable = ['user_id', 'raw', 'kind', 'context'];
}
