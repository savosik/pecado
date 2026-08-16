<?php

namespace App\Models;

use App\Enums\Crm\ManagerAbsenceType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Период отсутствия менеджера отдела продаж (отпуск, отгул, больничный, прогул).
 *
 * Обе ссылки на менеджеров — карточки personal_managers, не учётки users.
 * Замещение действует только при заполненном substitute_manager_id; резолв
 * «кто фактически ведёт клиентов на дату» — App\Services\Team\ManagerAbsenceResolver.
 *
 * @property int $id
 * @property int $personal_manager_id
 * @property int|null $substitute_manager_id
 * @property ManagerAbsenceType $type
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon $ends_on
 * @property string|null $comment
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read PersonalManager $manager
 * @property-read PersonalManager|null $substitute
 * @property-read User|null $author
 *
 * @mixin \Eloquent
 */
class ManagerAbsence extends Model
{
    use HasFactory;

    protected $fillable = [
        'personal_manager_id',
        'substitute_manager_id',
        'type',
        'starts_on',
        'ends_on',
        'comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ManagerAbsenceType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * Отсутствующий менеджер (карточка).
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'personal_manager_id');
    }

    /**
     * Замещающий менеджер (карточка); NULL — отсутствие без замещения.
     */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(PersonalManager::class, 'substitute_manager_id');
    }

    /**
     * Кто внёс запись (учётка CRM).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Отсутствия, действующие в указанную дату (границы включительно).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date);
    }

    /**
     * Отсутствия, пересекающиеся с периодом (границы включительно).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from);
    }
}
