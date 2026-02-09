<?php

namespace App\Services\ProductExport\Fields;

use App\Services\ProductExport\ProductColumnField;

class RecommendedPriceField extends ProductColumnField
{
    public function key(): string { return 'recommended_price'; }
    public function name(): string { return 'Рекомендуемая розничная цена'; }
    public function description(): string { return 'Рекомендованная розничная цена (РРЦ)'; }
    public function group(): string { return 'Основные'; }
    protected function column(): string { return 'recommended_price'; }
    protected function columnType(): string { return 'numeric'; }
    public function isExportable(): bool { return false; } // только фильтр
}
