<?php

namespace App\Exceptions;

use Exception;

/**
 * В заказ попала позиция, у которой нет цены вообще: ни базовой в карточке,
 * ни индивидуальной от 1С.
 *
 * Оформлять такую позицию нельзя. Раньше она уходила в 1С строкой с нулём —
 * документ в 1С не проводился, а клиент фактически получал товар даром.
 */
class ProductWithoutPriceException extends Exception
{
    /**
     * @param  list<array{product_id: int, sku: string|null, name: string}>  $items
     */
    public function __construct(string $message, protected array $items = [])
    {
        parent::__construct($message);
    }

    /**
     * @return list<array{product_id: int, sku: string|null, name: string}>
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
