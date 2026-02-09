<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class IsNewField extends ProductColumnField
{
    public function key(): string { return 'is_new'; }
    public function name(): string { return 'Новинка'; }
    public function description(): string { return 'Флаг «Новинка» для выделения в каталоге'; }
    public function group(): string { return 'Флаги'; }
    protected function column(): string { return 'is_new'; }
    protected function columnType(): string { return 'boolean'; }
}
