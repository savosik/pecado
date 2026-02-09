<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class ExternalIdField extends ProductColumnField
{
    public function key(): string { return 'external_id'; }
    public function name(): string { return 'Внешний ID'; }
    public function description(): string { return 'Внешний идентификатор товара из другой системы'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'external_id'; }
    protected function columnType(): string { return 'text'; }
}
