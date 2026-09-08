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
 * Запись реестра закрепления: кто вёл партнёра и в какой период.
 *
 * users.personal_manager_id остаётся действующим значением — реестр хранит историю,
 * без которой атрибуция прошлых периодов идёт по сегодняшнему состоянию.
 *
 * Переход применяется с 1-го числа следующего расчётного периода: отгрузки внутри
 * месяца между работниками не дробятся, иначе снимок перестаёт быть воспроизводимым.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $personal_manager_id
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon|null $ends_on
 * @property string $reason
 * @property string|null $plan_delta
 * @property string|null $comment
 * @property int|null $author_id
 */
class MotivationPartnerAssignment extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationPartnerAssignmentFactory> */
    use HasFactory;

    public const REASON_INITIAL = 'initial';

    public const REASON_POOL_PACKAGE = 'pool_package';

    public const REASON_TRANSFER = 'transfer';

    public const REASON_RETURN_TO_POOL = 'return_to_pool';

    public const REASON_ABSENCE = 'absence';

    protected $fillable = [
        'user_id',
        'personal_manager_id',
        'starts_on',
        'ends_on',
        'reason',
        'plan_delta',
        'comment',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'plan_delta' => 'decimal:2',
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

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Закрепления, действовавшие в указанный день.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveOn(Builder $query, Carbon $day): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day));
    }
}
