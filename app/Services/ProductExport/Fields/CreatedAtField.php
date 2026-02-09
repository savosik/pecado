<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class CreatedAtField extends ProductColumnField
{
    public function key(): string { return 'created_at'; }
    public function name(): string { return 'Дата создания'; }
    public function description(): string { return 'Дата и время создания товара в системе'; }
    public function group(): string { return 'Служебные'; }
    public function filterGroup(): string { return 'Даты'; }
    protected function column(): string { return 'created_at'; }
    protected function columnType(): string { return 'date'; }
}
