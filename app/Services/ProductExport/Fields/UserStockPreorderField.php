<?php

namespace App\Services\ProductExport\Fields;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class UserStockPreorderField extends ExportField
{
    public function __construct(protected StockServiceInterface $stockService) {}

    public function key(): string { return 'user_stock_preorder'; }
    public function name(): string { return 'Остаток (предзаказ, по региону клиента)'; }
    public function description(): string { return 'Количество товара доступное для предзаказа'; }
    public function group(): string { return 'Пользовательские (по клиенту)'; }
    public function isFilterable(): bool { return false; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        if (!$clientUser) return 0;
        return $this->stockService->getPreorderStock($product, $clientUser);
    }
}
