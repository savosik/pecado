<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\RelationFilterField;

class WarehouseFilterField extends RelationFilterField
{
    public function key(): string { return 'warehouse_id'; }
    public function name(): string { return 'Склад'; }
    public function group(): string { return 'Связи'; }
    public function searchUrl(): ?string { return '/admin/warehouses/search'; }
    protected function filterMode(): string { return 'relation'; }
    protected function relation(): string { return 'warehouses'; }
    protected function relationKey(): string { return 'warehouses.id'; }
}
