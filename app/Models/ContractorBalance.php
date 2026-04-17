<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractorBalance extends Model
{
    use HasFactory;

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
