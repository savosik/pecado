<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\ClientDocumentFilterField;

class InOrdersFilterField extends ClientDocumentFilterField
{
    public function key(): string
    {
        return 'in_orders';
    }

    public function name(): string
    {
        return 'Содержится в заказах';
    }

    public function searchUrl(): ?string
    {
        return '/admin/product-exports/filter-options?type=orders';
    }

    protected function itemsRelation(): string
    {
        return 'orderItems';
    }

    protected function documentColumn(): string
    {
        return 'order_id';
    }

    protected function documentRelation(): string
    {
        return 'order';
    }
}
