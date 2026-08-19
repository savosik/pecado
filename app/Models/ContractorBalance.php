<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property string|null $contractor_uuid UUID контрагента из 1С
 * @property string $tax_id ИНН контрагента
 * @property numeric $current_balance Текущий баланс (RUB)
 * @property numeric $overdue_debt Просроченная задолженность (RUB)
 * @property \Illuminate\Support\Carbon|null $balance_erp_updated_at Дата обновления баланса из 1С
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ContractorBalanceOverdueDetail> $overdueDetails
 * @property-read int|null $overdue_details_count
 * @property-read \App\Models\User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereBalanceErpUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereContractorUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereCurrentBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereOverdueDebt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereTaxId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalance whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ContractorBalance extends Model
{
    use HasFactory;

    /**
     * Имя глобального scope, скрывающего исключённых из обмена контрагентов.
     * Балансы маркетплейсов из erp.excluded_contractor_tax_ids 1С не обновляет
     * (они вырезаны из settlement.* и balance.updated), поэтому их снимки
     * заморожены и в витрине/CRM выглядят как вечный «долг» без расшифровки.
     */
    public const SCOPE_WITHOUT_EXCLUDED = 'withoutExcludedContractors';

    protected static function booted(): void
    {
        static::addGlobalScope(self::SCOPE_WITHOUT_EXCLUDED, function ($query): void {
            $excluded = config('erp.excluded_contractor_tax_ids', []);

            if ($excluded !== []) {
                $query->whereNotIn('tax_id', $excluded);
            }
        });
    }

    protected $fillable = [
        'user_id',
        'company_id',
        'contractor_uuid',
        'tax_id',
        'current_balance',
        'overdue_debt',
        'balance_erp_updated_at',
    ];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'overdue_debt' => 'decimal:2',
        'balance_erp_updated_at' => 'datetime',
    ];

    /**
     * Пользователь — владелец этого контрагента.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Компания в системе (если найдена по ИНН).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Детализация просрочки по реализациям.
     */
    public function overdueDetails(): HasMany
    {
        return $this->hasMany(ContractorBalanceOverdueDetail::class);
    }
}
