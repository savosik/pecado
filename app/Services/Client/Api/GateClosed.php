<?php

namespace App\Services\Client\Api;

use App\Support\OperationApi\OperationDenied;

/**
 * Раздел кабинета, к которому относится операция, для клиента закрыт.
 *
 * Отдельный наследник {@see OperationDenied}: агенту нужен машиночитаемый код
 * («документы выключены», «резерв недоступен»), чтобы отличать это от
 * «операция закрыта для API» и не пробовать снова.
 */
class GateClosed extends OperationDenied
{
    public function __construct(public readonly FeatureGate $gate)
    {
        parent::__construct($gate->reason());
    }

    public function code(): string
    {
        return $this->gate->code();
    }
}
