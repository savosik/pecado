<?php

namespace App\Models\Motivation;

use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Зачёт одного партнёра в квартальную премию (п. 7.3).
 *
 * Хранится построчно намеренно: порог проверяется по каждому партнёру отдельно,
 * а не делением совокупного объёма на количество.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $quarter_start
 * @property int $user_id
 * @property int|null $personal_manager_id
 * @property string $shipments_amount
 * @property string $returns_amount
 * @property bool $qualified
 * @property \Illuminate\Support\Carbon|null $computed_at
 */
class MotivationQuarterlyQualification extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationQuarterlyQualificationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'quarter_start',
        'user_id',
        'personal_manager_id',
        'shipments_amount',
        'returns_amount',
        'qualified',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'quarter_start' => 'date',
            'shipments_amount' => 'decimal:2',
            'returns_amount' => 'decimal:2',
            'qualified' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PersonalManager, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForQuarter(Builder $query, Carbon $quarterStart): Builder
    {
        return $query->whereDate('quarter_start', $quarterStart->copy()->startOfQuarter());
    }
}
