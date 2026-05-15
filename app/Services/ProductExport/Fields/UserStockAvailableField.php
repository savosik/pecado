<?php

namespace App\Services\ProductExport\Fields;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class UserStockAvailableField extends ExportField
{
    public function __construct(protected StockServiceInterface $stockService) {}

    public function key(): string
    {
        return 'user_stock_available';
    }

    public function name(): string
    {
        return 'Остаток (основной, по региону клиента)';
    }

    public function description(): string
    {
        return 'Доступный остаток товара для клиента по региону';
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

        // Чанк-кеш: stockService->getAvailableStockMap делает 2 запроса на
        // весь чанк (не на товар), результат лежит в exportRowCache.
        if ($product->hasExportRowCache('stock_available')) {
            return $product->getExportRowCache('stock_available') ?? 0;
        }

        return $this->stockService->getAvailableStock($product, $clientUser);
    }
}
