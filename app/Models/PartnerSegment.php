<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * US-12: Сегмент партнёров.
 * Группа партнёров (пользователей) из 1С, используемая для расчёта скидок (US-03).
 */
class PartnerSegment extends Model
{
    protected $fillable = [
        'uuid',
        'name',
    ];

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'partner_user');
    }
}
