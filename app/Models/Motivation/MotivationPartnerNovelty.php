<?php

namespace App\Models\Motivation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Кэш новизны партнёра: с какого и по какое число его отгрузки идут в показатель П2.
 *
 * Истина — в отгрузках; строка здесь лишь избавляет каждый экран и каждый расчёт от
 * прохода по всей истории партнёра. В снимок расчёта попадает зафиксированной.
 *
 * Флаг history_incomplete отмечает партнёров, чью первую отгрузку невозможно отличить
 * от начала выгрузки из 1С. На начисление он не влияет — экран обязан его показывать.
 *
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $first_shipment_on
 * @property \Illuminate\Support\Carbon|null $last_shipment_before_gap_on
 * @property int|null $gap_days
 * @property \Illuminate\Support\Carbon|null $novelty_started_on
 * @property \Illuminate\Support\Carbon|null $novelty_ends_on
 * @property string|null $source
 * @property bool $history_incomplete
 * @property \Illuminate\Support\Carbon|null $computed_at
 */
class MotivationPartnerNovelty extends Model
{
    /** @use HasFactory<\Database\Factories\Motivation\MotivationPartnerNoveltyFactory> */
    use HasFactory;

    public const SOURCE_POOL = 'pool';

    public const SOURCE_OWN = 'own';

    public const SOURCE_UNKNOWN = 'unknown';

    protected $table = 'motivation_partner_novelty';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'first_shipment_on',
        'last_shipment_before_gap_on',
        'gap_days',
        'novelty_started_on',
        'novelty_ends_on',
        'source',
        'history_incomplete',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_shipment_on' => 'date',
            'last_shipment_before_gap_on' => 'date',
            'novelty_started_on' => 'date',
            'novelty_ends_on' => 'date',
            'gap_days' => 'integer',
            'history_incomplete' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Идёт ли отгрузка этого дня в показатель П2.
     */
    public function isNewOn(CarbonInterface $day): bool
    {
        if ($this->novelty_started_on === null || $this->novelty_ends_on === null) {
            return false;
        }

        return $day->betweenIncluded($this->novelty_started_on, $this->novelty_ends_on);
    }

    /**
     * Партнёры, чей Период новизны захватывает указанный расчётный месяц.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNewInMonth(Builder $query, CarbonInterface $month): Builder
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();
        $end = CarbonImmutable::instance($month)->endOfMonth();

        return $query
            ->whereNotNull('novelty_started_on')
            ->whereDate('novelty_started_on', '<=', $end)
            ->whereDate('novelty_ends_on', '>=', $start);
    }
}
