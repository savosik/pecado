<?php

namespace App\Models\Motivation;

use App\Models\PayrollScheme;
use App\Models\PersonalManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Справочный снимок расчёта по «другой» схеме в переходный период (п. 12.2).
 *
 * @property int $id
 * @property int $personal_manager_id
 * @property \Illuminate\Support\Carbon $period_month
 * @property int $scheme_id
 * @property array<string, mixed> $params_effective
 * @property array<string, mixed> $inputs
 * @property array<string, mixed> $breakdown
 * @property string $total
 * @property string|null $inputs_hash
 * @property \Illuminate\Support\Carbon|null $computed_at
 */
class MotivationShadowCalculation extends Model
{
    protected $fillable = [
        'personal_manager_id',
        'period_month',
        'scheme_id',
        'params_effective',
        'inputs',
        'breakdown',
        'total',
        'inputs_hash',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'params_effective' => 'array',
            'inputs' => 'array',
            'breakdown' => 'array',
            'total' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(PayrollScheme::class, 'scheme_id');
    }
}
