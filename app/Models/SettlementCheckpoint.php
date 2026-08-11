<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Контрольная точка сальдо взаиморасчётов (v16.0.0).
 *
 * 1С отдаёт ленту движений с 01.01.2026, но сверенным считает только сальдо на дату
 * закрытия периода — 01.07.2026 (дата запрета изменения 30.06.2026). Контрольная точка
 * превращает это в измеримую величину: «сальдо на 01.01 + движения первого полугодия»
 * обязано сойтись с точкой на 01.07.
 *
 * ⚠️ Это контрольная сумма, а не источник данных. Читает её только команда сверки.
 * Строить на ней баланс, отчёты или показ клиенту нельзя: точка отражает состояние
 * на давнюю дату, а не сейчас.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $organization_id
 * @property string $contractor_uuid
 * @property string $organization_uuid
 * @property string|null $tax_id
 * @property \Illuminate\Support\Carbon $as_of_date
 * @property string $currency_code
 * @property numeric $amount
 * @property numeric|null $amount_rub
 * @property bool $is_verified
 * @property \Illuminate\Support\Carbon|null $erp_updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Organization|null $organization
 *
 * @method static \Database\Factories\SettlementCheckpointFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
class SettlementCheckpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'organization_id',
        'contractor_uuid',
        'organization_uuid',
        'tax_id',
        'as_of_date',
        'currency_code',
        'amount',
        'amount_rub',
        'is_verified',
        'erp_updated_at',
    ];

    protected $attributes = [
        // Пустая строка, а не NULL: уникальный индекс в MySQL считает NULL-ы
        // различными, и точка без организации задваивалась бы при повторной доставке.
        'organization_uuid' => '',
        'currency_code' => 'RUB',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'amount' => 'decimal:2',
            'amount_rub' => 'decimal:2',
            'is_verified' => 'boolean',
            'erp_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Только сверенные бухгалтерией точки. Начало ленты (01.01.2026) сюда не попадает:
     * оно техническое, и принимать его за проверенное сальдо нельзя.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->where('is_verified', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAsOf(Builder $query, Carbon $date): void
    {
        $query->whereDate('as_of_date', $date->toDateString());
    }
}
