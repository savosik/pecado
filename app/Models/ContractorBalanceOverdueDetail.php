<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $contractor_balance_id
 * @property string $shipment_uuid UUID реализации из 1С
 * @property numeric $amount Сумма просрочки
 * @property \Illuminate\Support\Carbon $due_date Дата оплаты
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ContractorBalance $contractorBalance
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail whereContractorBalanceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail whereDueDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail whereShipmentUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ContractorBalanceOverdueDetail whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
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
