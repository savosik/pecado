<?php

namespace App\Services\Order;

use App\Models\Product;

/**
 * Одна строка будущего заказа.
 *
 * Цена задаётся двумя способами, и это принципиально:
 *  - `$price === null` — посчитать по прайсу клиента (индивидуальная цена со скидкой);
 *  - `$price` задана — фиксированная цена строки, скидки не применяются.
 *
 * Второй случай — уценка: цена партии зафиксирована в корзине, и пересчитывать её
 * по прайсу нельзя. Позже так же пойдут промо-позиции с ценой из правила акции.
 */
final readonly class OrderLine
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public ?float $price = null,
        public ?int $productDefectId = null,
        public ?string $defectDescription = null,
    ) {}

    /**
     * Строка уценки: фиксированная цена партии плюс снапшот описания дефекта.
     *
     * Снапшот нужен потому, что партию могут отредактировать позже, а в заказе
     * должно остаться то, что видел клиент.
     */
    public static function defect(
        Product $product,
        int $quantity,
        float $price,
        ?int $productDefectId,
        ?string $defectDescription,
    ): self {
        return new self($product, $quantity, $price, $productDefectId, $defectDescription);
    }
}
