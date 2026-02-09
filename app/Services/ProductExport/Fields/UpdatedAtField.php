<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class UpdatedAtField extends ProductColumnField
{
    public function key(): string { return 'updated_at'; }
    public function name(): string { return 'Дата обновления'; }
    public function description(): string { return 'Дата и время последнего обновления товара'; }
    public function group(): string { return 'Служебные'; }
    public function filterGroup(): string { return 'Даты'; }
    protected function column(): string { return 'updated_at'; }
    protected function columnType(): string { return 'date'; }
}
