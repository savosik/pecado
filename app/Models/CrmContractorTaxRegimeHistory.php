<?php

namespace App\Models;

use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\VatPreference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала ответов о налоговом режиме юрлица.
 *
 * Журнал нужен отчёту так же, как текущее значение: «когда партнёр передумал»
 * и «кто последним с ним говорил» — из текущей строки не восстановить.
 *
 * @property int $id
 * @property int $company_id
 * @property string $action
 * @property TaxRegime|null $current_regime
 * @property TaxRegime|null $planned_regime
 * @property int|null $planned_year
 * @property VatPreference|null $vat_preference
 * @property string $source
 * @property string|null $note
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Company $company
 * @property-read User|null $author
 */
class CrmContractorTaxRegimeHistory extends Model
{
    public const ACTION_SAVED = 'saved';

    public const ACTION_CONFIRMED = 'confirmed';

    public const UPDATED_AT = null;

    protected $table = 'crm_contractor_tax_regime_history';

    protected $fillable = [
        'company_id',
        'action',
        'current_regime',
        'planned_regime',
        'planned_year',
        'vat_preference',
        'source',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'current_regime' => TaxRegime::class,
            'planned_regime' => TaxRegime::class,
            'planned_year' => 'integer',
            'vat_preference' => VatPreference::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
