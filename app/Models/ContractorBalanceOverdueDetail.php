<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $contractor_balance_id
 * @property int|null $shipment_id Реализация сайта (shipments.id)
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
        'organization_id',
        'shipment_id',
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

    /**
     * Реализация сайта, соответствующая `shipment_uuid`.
     *
     * NULL, пока реализация не пришла из 1С: баланс и реализации идут разными
     * очередями без гарантии порядка. Связь доклеивается в HandleShipmentCreated.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Наша организация просроченной реализации (v15.8.0).
     *
     * Если 1С не прислала организацию в детали, выводится по `shipment_uuid`
     * из самой реализации. Не вывелась — NULL, сумма всё равно учитывается.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
