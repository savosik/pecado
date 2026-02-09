<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class IdField extends ProductColumnField
{
    public function key(): string { return 'id'; }
    public function name(): string { return 'ID товара'; }
    public function description(): string { return 'Числовой идентификатор товара в системе'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'id'; }
    protected function columnType(): string { return 'numeric'; }
    public function isFilterable(): bool { return false; }
}
