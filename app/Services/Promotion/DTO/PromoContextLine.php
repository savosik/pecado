<?php

namespace App\Services\Promotion\DTO;

/**
 * Одна позиция в контексте расчёта акций.
 *
 * Движок работает со снапшотом позиций, а не с моделью Cart: заказ по клиентскому API
 * создаётся вообще без корзины (см. docs/promo-constructor-roadmap.md).
 */
final readonly class PromoContextLine
{
    /**
     * @param  int  $productId  Товар (products.id)
     * @param  int  $quantity  Количество
     * @param  float|null  $unitPrice  Цена за штуку в валюте контекста; NULL — движок возьмёт
     *                                 финальную цену клиента сам (батчем)
     * @param  bool  $isPromo  Строка сама является промо-позицией — в агрегаты не входит
     * @param  string  $itemType  Тип позиции: instock, preorder, defect
     */
    public function __construct(
        public int $productId,
        public int $quantity,
        public ?float $unitPrice = null,
        public bool $isPromo = false,
        public string $itemType = 'instock',
    ) {}
}
