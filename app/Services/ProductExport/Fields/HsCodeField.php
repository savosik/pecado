<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class HsCodeField extends ProductColumnField
{
    public function key(): string
    {
        return 'hs_code';
    }

    public function name(): string
    {
        return 'Код ТН ВЭД';
    }

    public function description(): string
    {
        return 'Код ТН ВЭД из «Номенклатура.КодТНВЭД.Код», до 20 символов';
    }

    public function group(): string
    {
        return 'Габариты и вес';
    }

    protected function column(): string
    {
        return 'hs_code';
    }

    protected function columnType(): string
    {
        return 'text';
    }
}
