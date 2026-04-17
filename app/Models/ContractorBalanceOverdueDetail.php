<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorBalanceOverdueDetail extends Model
{
    protected $fillable = [
        'contractor_balance_id',
        'shipment_uuid',
        'amount',
        'due_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    /**
     * Баланс контрагента, к которому относится эта просрочка.
     */
    public function contractorBalance(): BelongsTo
    {
        return $this->belongsTo(ContractorBalance::class);
    }
}
