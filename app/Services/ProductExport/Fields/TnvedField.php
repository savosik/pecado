<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class TnvedField extends ProductColumnField
{
    public function key(): string { return 'tnved'; }
    public function name(): string { return 'Код ТН ВЭД'; }
    public function description(): string { return 'Код ТН ВЭД для таможенного оформления'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'tnved'; }
    protected function columnType(): string { return 'text'; }
    public function isFilterable(): bool { return false; }
}
