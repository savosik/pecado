<?php

namespace App\Contracts\Promotion;

/**
 * Доступность промо-позиции на складе-источнике.
 *
 * Фонд промо-позиций = остаток склада, который 1С передаёт по шине; отдельного
 * ручного учёта нет. Реализация с настоящей проверкой — карточка promo-07
 * (`PromoStockService`), до тех пор работает заглушка AlwaysAvailablePromoStock:
 * волна 1 ничего не выдаёт, и её корректность от остатков не зависит.
 */
interface PromoStockCheckerInterface
{
    /**
     * Хватает ли остатка, чтобы выдать промо-позицию.
     *
     * @param  int  $productId  Товар награды (products.id)
     * @param  int|null  $warehouseId  Склад-источник (warehouses.id); NULL — склад региона клиента
     * @param  int  $quantity  Сколько единиц нужно
     * @param  int|null  $userId  Клиент — для выбора склада по региону
     */
    public function isAvailable(int $productId, ?int $warehouseId, int $quantity, ?int $userId = null): bool;

    /**
     * Сколько единиц можно выдать.
     *
     * Нужно движку, чтобы урезать кратность: если по условию положено 5 штук,
     * а свободно 2 — выдаём 2, а не «ничего» и не «5».
     */
    public function availableFor(int $productId, ?int $warehouseId, ?int $userId = null): int;
}
