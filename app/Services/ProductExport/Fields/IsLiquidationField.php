<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class IsLiquidationField extends ProductColumnField
{
    public function key(): string { return 'is_liquidation'; }
    public function name(): string { return 'Товар в ликвидации'; }
    public function description(): string { return 'Флаг ликвидации — товар распродаётся'; }
    public function group(): string { return 'Флаги'; }
    protected function column(): string { return 'is_liquidation'; }
    protected function columnType(): string { return 'boolean'; }
}
