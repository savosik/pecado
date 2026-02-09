<?php

namespace App\Services\ProductExport\Fields;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class DiscountedPriceField extends ExportField
{
    public function __construct(protected PriceServiceInterface $priceService) {}

    public function key(): string { return 'discounted_price'; }
    public function name(): string { return 'Цена со скидкой клиента'; }
    public function description(): string { return 'Цена с учётом персональной скидки клиента'; }
    public function group(): string { return 'Пользовательские (по клиенту)'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'price'; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        if (!$clientUser) return $product->base_price;
        return round($this->priceService->getDiscountedPrice($product, $clientUser), 2);
    }
}
