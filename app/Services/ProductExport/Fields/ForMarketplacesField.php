<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class ForMarketplacesField extends ProductColumnField
{
    public function key(): string { return 'for_marketplaces'; }
    public function name(): string { return 'Для маркетплейсов'; }
    public function description(): string { return 'Флаг доступности товара для маркетплейсов'; }
    public function group(): string { return 'Флаги'; }
    protected function column(): string { return 'for_marketplaces'; }
    protected function columnType(): string { return 'boolean'; }
}
