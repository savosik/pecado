<?php

namespace App\Models;

use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\TaxRegimeFreshness;
use App\Enums\Crm\VatPreference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Налоговый режим юрлица партнёра: сейчас, план на следующий год и важность НДС.
 *
 * Ответ даёт менеджер со слов партнёра или сам клиент в опросе на сайте
 * (`source`). Правило актуальности здесь в двух видах — {@see freshUntil()}
 * для карточки и {@see constrainUpToDate()} для списков и задач. Меняя одно,
 * меняй и другое: совпадение закреплено тестом.
 *
 * @property int $id
 * @property int $company_id
 * @property TaxRegime|null $current_regime
 * @property TaxRegime|null $planned_regime
 * @property int|null $planned_year
 * @property VatPreference|null $vat_preference
 * @property string $source
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property int|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User|null $confirmer
 */
class CrmContractorTaxRegime extends Model
{
    public const SOURCE_MANAGER = 'manager';

    public const SOURCE_CLIENT = 'client';

    protected $fillable = [
        'company_id',
        'current_regime',
        'planned_regime',
        'planned_year',
        'vat_preference',
        'source',
        'note',
        'confirmed_at',
        'confirmed_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => self::SOURCE_MANAGER,
    ];

    protected function casts(): array
    {
        return [
            'current_regime' => TaxRegime::class,
            'planned_regime' => TaxRegime::class,
            'planned_year' => 'integer',
            'vat_preference' => VatPreference::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Год, про который спрашиваем план, — всегда следующий.
     */
    public static function targetYear(): int
    {
        return now()->year + 1;
    }

    public function isFilled(): bool
    {
        return $this->current_regime !== null && $this->planned_regime !== null;
    }

    /**
     * Момент, когда ответ перестаёт быть актуальным.
     *
     * Два срока, срабатывает ранний: давность подтверждения и 1 января года,
     * на который составлен план, — в этот день план стал текущим режимом.
     */
    public function freshUntil(): ?CarbonImmutable
    {
        if ($this->confirmed_at === null || $this->planned_year === null) {
            return null;
        }

        $byAge = CarbonImmutable::instance($this->confirmed_at)->addDays($this->confirmDays());
        $byYear = CarbonImmutable::create($this->planned_year, 1, 1, 0, 0, 0);

        return $byAge->min($byYear);
    }

    public static function freshnessOf(?self $regime): TaxRegimeFreshness
    {
        if ($regime === null || ! $regime->isFilled()) {
            return TaxRegimeFreshness::MISSING;
        }

        $until = $regime->freshUntil();

        return $until !== null && now()->lessThan($until)
            ? TaxRegimeFreshness::FRESH
            : TaxRegimeFreshness::OUTDATED;
    }

    /**
     * Заполненные ответы: известны и режим сейчас, и план.
     *
     * Статический метод, а не scope: вызывается внутри whereHas, где статический
     * анализ не знает модель построителя и магический scope для него не существует.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function constrainFilled(Builder $query): Builder
    {
        return $query->whereNotNull('current_regime')->whereNotNull('planned_regime');
    }

    /**
     * Актуальные ответы — SQL-двойник {@see freshnessOf()}.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function constrainUpToDate(Builder $query): Builder
    {
        $undecided = TaxRegime::UNDECIDED->value;

        return self::constrainFilled($query)
            // Явные NOT NULL, а не только сравнения: `NULL > дата` даёт NULL, и
            // отрицание «актуально» потеряло бы неподтверждённые ответы — трёхзначная логика SQL.
            ->whereNotNull('confirmed_at')
            ->whereNotNull('planned_year')
            // «now < 1 января planned_year» равносильно «planned_year > текущего года».
            ->where('planned_year', '>=', self::targetYear())
            ->where(fn (Builder $ages) => $ages
                ->where(fn (Builder $decided) => $decided
                    ->where('planned_regime', '!=', $undecided)
                    ->where('confirmed_at', '>', now()->subDays((int) config('crm_tax_regime.confirm_days'))))
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('planned_regime', $undecided)
                    ->where('confirmed_at', '>', now()->subDays((int) config('crm_tax_regime.undecided_confirm_days')))));
    }

    private function confirmDays(): int
    {
        return $this->planned_regime === TaxRegime::UNDECIDED
            ? (int) config('crm_tax_regime.undecided_confirm_days')
            : (int) config('crm_tax_regime.confirm_days');
    }
}
