<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Взаиморасчёты в разрезе нашей организации (v15.8.0).
 *
 * Третий уровень поверх `ContractorBalance`: партнёр → контрагент клиента → наша
 * организация. Нужен потому, что клиент платит разным юрлицам на разные счета
 * и должен видеть, кому именно должен.
 *
 * `ContractorBalance` при этом остаётся агрегатом-проекцией и продолжает работать
 * как раньше — детализация живёт рядом, а не внутри.
 *
 * @property int $id
 * @property int $user_id
 * @property int $company_id
 * @property int $organization_id
 * @property numeric $current_balance
 * @property numeric $overdue_debt
 * @property \Illuminate\Support\Carbon|null $balance_erp_updated_at
 *
 * @mixin \Eloquent
 */
class ContractorOrganizationBalance extends Model
{
    /**
     * Как и агрегат ContractorBalance, разрез по организациям скрывает
     * контрагентов из erp.excluded_contractor_tax_ids: 1С их балансы не шлёт,
     * снимки заморожены. Ключа tax_id здесь нет — фильтруем через companies.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(ContractorBalance::SCOPE_WITHOUT_EXCLUDED, function ($query): void {
            $excluded = config('erp.excluded_contractor_tax_ids', []);

            if ($excluded !== []) {
                $query->whereNotIn(
                    'company_id',
                    Company::query()->select('id')->whereIn('tax_id', $excluded),
                );
            }
        });
    }

    protected $fillable = [
        'user_id',
        'company_id',
        'organization_id',
        'current_balance',
        'overdue_debt',
        'balance_erp_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'overdue_debt' => 'decimal:2',
            'balance_erp_updated_at' => 'datetime',
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
}
