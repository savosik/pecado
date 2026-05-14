<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ProductColumnField;

class BarcodeField extends ProductColumnField
{
    public function key(): string
    {
        return 'barcode';
    }

    public function name(): string
    {
        return 'Штрихкод (основной)';
    }

    public function description(): string
    {
        return 'Основной штрихкод товара (EAN / UPC). Если в карточке поле пусто — берётся первый из связанных штрихкодов.';
    }

    public function group(): string
    {
        return 'Основные';
    }

    protected function column(): string
    {
        return 'barcode';
    }

    protected function columnType(): string
    {
        return 'text';
    }

    public function eagerLoad(): array
    {
        return ['barcodes'];
    }

    /**
     * Если в `products.barcode` пусто — фолбэчим на первый связанный из
     * `product_barcodes`. Why: значительная часть товаров хранит ШК только
     * в pivot (через ERP-синхронизацию), а в скалярном `barcode` остаются
     * NULL — без фолбэка партнёрские выгрузки получали пустую колонку.
     */
    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $direct = parent::getValue($product, $clientUser);
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        if ($product->relationLoaded('barcodes')) {
            return $product->barcodes->first()?->barcode;
        }

        return $product->barcodes()->value('barcode');
    }
}
