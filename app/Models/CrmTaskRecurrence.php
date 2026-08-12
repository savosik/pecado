<?php

namespace App\Models;

use App\Enums\Crm\TaskPriority;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Правило автоповторяемой задачи.
 *
 * Хранит не задачу, а станок, который её производит: «каждый будний день
 * в 13:30 обзвонить спящих». Отмена цепочки — `is_active = false`, а не
 * удаление: уже созданные задачи остаются в истории вместе с отчётами
 * о закрытии.
 *
 * @property int $id
 * @property int $author_id
 * @property int $assignee_id
 * @property int|null $client_user_id
 * @property string $title
 * @property string|null $description
 * @property TaskPriority $priority
 * @property array<int, int> $weekdays
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon|null $ends_on
 * @property \Illuminate\Support\Carbon|null $last_generated_for
 * @property bool $is_active
 */
class CrmTaskRecurrence extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'author_id',
        'assignee_id',
        'client_user_id',
        'title',
        'description',
        'priority',
        'related_type',
        'related_id',
        'weekdays',
        'due_time',
        'starts_on',
        'ends_on',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'last_generated_for' => 'date',
            'is_active' => 'boolean',
            'priority' => TaskPriority::class,
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(CrmTaskOccurrence::class, 'recurrence_id');
    }

    /**
     * Правила, которые могут породить задачу на эту дату.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueOn(Builder $query, CarbonInterface $date): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $date));
    }

    /**
     * Выпадает ли правило на эту дату по маске дней недели.
     *
     * ISO-8601: 1 — понедельник, 7 — воскресенье. Именно ISO, а не `dayOfWeek`
     * Carbon, где неделя начинается с воскресенья и нуля — «будние дни»
     * в такой нумерации записывались бы как [1,2,3,4,5] со сдвигом.
     */
    public function matchesDate(CarbonInterface $date): bool
    {
        return in_array((int) $date->isoWeekday(), array_map('intval', $this->weekdays ?? []), true);
    }

    /**
     * Человекочитаемое расписание для интерфейса.
     */
    public function scheduleLabel(): string
    {
        $names = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
        $days = array_map('intval', $this->weekdays ?? []);
        sort($days);

        $label = $days === [1, 2, 3, 4, 5]
            ? 'каждый будний день'
            : implode(', ', array_map(fn (int $day): string => $names[$day] ?? '?', $days));

        return trim($label.' в '.substr((string) $this->due_time, 0, 5));
    }
}
