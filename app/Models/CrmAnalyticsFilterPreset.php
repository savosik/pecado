<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Личный пресет фильтров отчёта продаж CRM.
 *
 * Хранит снимок набора фильтров ({@see \App\Http\Controllers\Crm\AnalyticsController}),
 * чтобы сотрудник мог быстро вернуться к сохранённой выборке.
 */
class CrmAnalyticsFilterPreset extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
