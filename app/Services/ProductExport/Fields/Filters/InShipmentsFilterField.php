<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\ClientDocumentFilterField;

class InShipmentsFilterField extends ClientDocumentFilterField
{
    public function key(): string
    {
        return 'in_shipments';
    }

    public function name(): string
    {
        return 'Содержится в реализациях';
    }

    public function searchUrl(): ?string
    {
        return '/admin/product-exports/filter-options?type=shipments';
    }

    protected function itemsRelation(): string
    {
        return 'shipmentItems';
    }

    protected function documentColumn(): string
    {
        return 'shipment_id';
    }

    protected function documentRelation(): string
    {
        return 'shipment';
    }
}
