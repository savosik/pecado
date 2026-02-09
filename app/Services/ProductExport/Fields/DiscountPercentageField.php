<?php

namespace App\Services\ProductExport\Fields;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class DiscountPercentageField extends ExportField
{
    public function __construct(protected PriceServiceInterface $priceService) {}

    public function key(): string { return 'discount_percentage'; }
    public function name(): string { return 'Процент скидки клиента'; }
    public function description(): string { return 'Размер персональной скидки клиента в процентах'; }
    public function group(): string { return 'Пользовательские (по клиенту)'; }
    public function isFilterable(): bool { return false; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        if (!$clientUser) return 0;
        $base = (float) $product->base_price;
        $discounted = $this->priceService->getDiscountedPrice($product, $clientUser);
        if ($base <= 0) return 0;
        return round((1 - $discounted / $base) * 100, 2);
    }
}
