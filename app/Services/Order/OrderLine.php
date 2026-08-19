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
 * Второй случай — уценка и промо: цена зафиксирована (партией или наградой акции),
 * и пересчитывать её по прайсу нельзя.
 *
 * `$warehouseId` — склад-победитель стопки региона, зафиксированный при
 * оформлении (только для строк наличия в регионах со стопкой). Сборщик
 * разбивает группу по нему на отдельные заказы; null — без разбиения.
 */
final readonly class OrderLine
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public ?float $price = null,
        public ?int $productDefectId = null,
        public ?string $defectDescription = null,
        public ?int $promotionRuleId = null,
        public ?string $promoKind = null,
        public ?int $warehouseId = null,
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

    /**
     * Строка промо-позиции: цена из награды акции плюс снапшот привязки.
     *
     * Снапшот, а не вычисление на лету: правило могут отредактировать или удалить,
     * а заказ обязан остаться таким, каким его оформил клиент.
     */
    public static function promo(
        Product $product,
        int $quantity,
        float $price,
        ?int $promotionRuleId,
        ?string $promoKind,
    ): self {
        return new self(
            product: $product,
            quantity: $quantity,
            price: $price,
            promotionRuleId: $promotionRuleId,
            promoKind: $promoKind,
        );
    }
}
