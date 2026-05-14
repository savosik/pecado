<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ProductColumnField;

class UrlField extends ProductColumnField
{
    public function key(): string
    {
        return 'url';
    }

    public function name(): string
    {
        return 'URL';
    }

    public function description(): string
    {
        return 'Полный URL страницы товара на сайте. Если в карточке поле пусто — генерируется как /products/{slug}.';
    }

    public function group(): string
    {
        return 'Основные';
    }

    protected function column(): string
    {
        return 'url';
    }

    protected function columnType(): string
    {
        return 'text';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    /**
     * Фолбэк на сгенерированный URL по slug. Why: в `products.url` сейчас
     * NULL у всех товаров — поле под партнёрский ручной override, которым
     * на проде никто не пользуется, поэтому выгрузка без фолбэка отдавала
     * пустую колонку.
     */
    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $direct = parent::getValue($product, $clientUser);
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return $product->slug
            ? url('/products/'.$product->slug)
            : null;
    }
}
