<?php

namespace App\Services\ProductExport\Fields;

use App\Contracts\Pricing\PriceServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class DiscountPercentageField extends ExportField
{
    public function __construct(protected PriceServiceInterface $priceService) {}

    public function key(): string
    {
        return 'discount_percentage';
    }

    public function name(): string
    {
        return 'Процент скидки клиента';
    }

    public function description(): string
    {
        return 'Размер персональной скидки клиента в процентах';
    }

    public function group(): string
    {
        return 'Пользовательские (по клиенту)';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function modifierType(): ?string
    {
        return 'numeric';
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        if (! $clientUser) {
            return 0;
        }

        // Берём готовый PriceResult из чанк-кеша если есть — иначе fallback
        // на per-product вызов. См. DiscountedPriceField для подробностей.
        /** @var \App\Contracts\Pricing\PriceResult|null $priceResult */
        $priceResult = $product->getExportRowCache('price_result');
        if ($priceResult === null) {
            $priceResult = $this->priceService->getPriceResult($product, $clientUser);
        }

        return $priceResult->discountPercent;
    }
}
