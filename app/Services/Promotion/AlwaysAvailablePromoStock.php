<?php

namespace App\Services\Promotion;

use App\Contracts\Promotion\PromoStockCheckerInterface;

/**
 * Заглушка проверки остатка промо-позиции для волны 1.
 *
 * Волна 1 ничего не выдаёт, поэтому остаток на результат не влияет. Настоящую
 * реализацию приносит карточка promo-07 (`PromoStockService`) — она подменит
 * биндинг в AppServiceProvider, движок трогать не придётся.
 */
class AlwaysAvailablePromoStock implements PromoStockCheckerInterface
{
    public function isAvailable(int $productId, ?int $warehouseId, int $quantity, ?int $userId = null): bool
    {
        return true;
    }

    public function availableFor(int $productId, ?int $warehouseId, ?int $userId = null): int
    {
        return PHP_INT_MAX;
    }
}
