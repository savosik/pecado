<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class BarcodeField extends ProductColumnField
{
    public function key(): string { return 'barcode'; }
    public function name(): string { return 'Штрихкод (основной)'; }
    public function description(): string { return 'Основной штрихкод товара (EAN / UPC)'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'barcode'; }
    protected function columnType(): string { return 'text'; }
}
