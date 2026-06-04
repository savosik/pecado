<?php

namespace App\Services\ProductExport\Fields;

use App\Contracts\Stock\StockServiceInterface;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProductExport\ExportField;

/**
 * Динамическое поле «Остаток по складу X».
 *
 * Один экземпляр создаётся на каждый склад в БД. Ключ — `warehouse.{id}.quantity`.
 * Возвращает остаток по конкретному складу для текущего товара.
 *
 * Why: партнёры-интеграторы ожидают отдельные колонки на каждый склад (msk, tmn,
 * spb и т.п.), а не объединённую строку «Склад: 5, Склад2: 0».
 */
class WarehouseQuantityField extends ExportField
{
    protected Warehouse $warehouse;

    public function __construct(Warehouse $warehouse, protected StockServiceInterface $stockService)
    {
        $this->warehouse = $warehouse;
    }

    public function getWarehouseId(): int
    {
        return $this->warehouse->id;
    }

    public function key(): string
    {
        return "warehouse.{$this->warehouse->id}.quantity";
    }

    public function name(): string
    {
        return "Остаток: {$this->warehouse->name}";
    }

    public function description(): string
    {
        return "Количество товара на складе «{$this->warehouse->name}»";
    }

    public function group(): string
    {
        return 'Складские остатки';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function modifierType(): ?string
    {
        return 'numeric';
    }

    public function eagerLoad(): array
    {
        return ['warehouses'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $w = $product->warehouses->firstWhere('id', $this->warehouse->id);

        return $w?->pivot->quantity ?? 0;
    }
}
