<?php

namespace App\Models\Motivation;

use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Доля работника в квартальной премии (п. 7.5).
 *
 * Сумма долей обязана равняться сумме премии; распределение возможно только после
 * утверждения итога квартала и попадает в расчётный лист отдельной строкой
 * за последний месяц квартала.
 *
 * @property int $id
 * @property int $bonus_id
 * @property int $personal_manager_id
 * @property string $amount
 * @property string|null $reason
 * @property int|null $author_id
 */
class MotivationQuarterlyShare extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationQuarterlyShareFactory> */
    use HasFactory;

    protected $fillable = [
        'bonus_id',
        'personal_manager_id',
        'amount',
        'reason',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<MotivationQuarterlyBonus, $this> */
    public function bonus(): BelongsTo
    {
        return $this->belongsTo(MotivationQuarterlyBonus::class, 'bonus_id');
    }

    /** @return BelongsTo<PersonalManager, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
