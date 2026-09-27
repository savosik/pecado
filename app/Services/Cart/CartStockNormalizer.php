<?php

namespace App\Services\Cart;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\User;

/**
 * Привести количества в корзине к доступному остатку.
 *
 * Для каждой строки: больше доступного — уменьшить до доступного; доступно 0 —
 * удалить строку. Уценка ограничена свободным остатком партии; закрытая или
 * снятая партия считается недоступной. Общая для кабинета и API v1.
 */
class CartStockNormalizer
{
    public function __construct(
        private readonly StockServiceInterface $stocks,
        private readonly DefectStockServiceInterface $defectStocks,
    ) {}

    /**
     * @return array{adjusted: int, removed: int, remaining_lines: int}
     */
    public function normalize(User $user, Cart $cart): array
    {
        $adjusted = 0;
        $removed = 0;

        $cart->load('items.product', 'items.productDefect');

        foreach ($cart->items as $item) {
            if (! $item->product) {
                continue;
            }

            if ($item->isDefect()) {
                $defect = $item->productDefect;
                $totalAvailable = ($defect && $defect->is_published && $defect->price !== null && ! $defect->isClosed())
                    ? $this->defectStocks->available($defect)
                    : 0;
            } else {
                $stock = $this->stocks->getStock($item->product, $user);
                $totalAvailable = (int) ($stock['available'] + $stock['preorder']);
            }

            if ($item->quantity <= $totalAvailable) {
                continue;
            }

            if ($totalAvailable <= 0) {
                $item->delete();
                $removed++;

                continue;
            }

            $item->quantity = $totalAvailable;
            $item->save();
            $adjusted++;
        }

        return [
            'adjusted' => $adjusted,
            'removed' => $removed,
            'remaining_lines' => $cart->items()->count(),
        ];
    }
}
