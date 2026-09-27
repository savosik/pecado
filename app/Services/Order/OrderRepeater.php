<?php

namespace App\Services\Order;

use App\Contracts\Cart\CartServiceInterface;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;

/**
 * Повторить заказ: положить его позиции в корзину.
 *
 * merge — прибавить к текущему количеству, replace — очистить корзину и положить
 * только позиции заказа. Позиции без привязки к каталогу пропускаются.
 */
class OrderRepeater
{
    public const MODE_MERGE = 'merge';

    public const MODE_REPLACE = 'replace';

    public function __construct(private readonly CartServiceInterface $carts) {}

    /**
     * @return array{mode: string, added_count: int, skipped_count: int, cart_totals: array<string, mixed>|null}
     */
    public function repeat(User $user, Order $order, string $mode = self::MODE_MERGE, ?Cart $cart = null): array
    {
        $order->load(['items:id,order_id,product_id,name,quantity']);

        $quantities = [];
        $skipped = 0;

        foreach ($order->items as $item) {
            $pid = (int) ($item->product_id ?? 0);
            $qty = (int) $item->quantity;

            if ($pid <= 0 || $qty <= 0) {
                $skipped++;

                continue;
            }

            $quantities[$pid] = ($quantities[$pid] ?? 0) + $qty;
        }

        $cart ??= $this->carts->getOrCreateActiveCart($user);

        if ($mode === self::MODE_REPLACE) {
            $cart->clear();
        }

        $cartTotals = null;

        if ($quantities !== []) {
            $targets = [];

            foreach ($quantities as $pid => $qty) {
                $current = (int) $cart->items()->where('product_id', $pid)->sum('quantity');
                $targets[$pid] = $current + $qty;
            }

            $result = $this->carts->setProductsQuantity($user, $cart, $targets);
            $cartTotals = $result['cart_totals'] ?? null;
        }

        return [
            'mode' => $mode,
            'added_count' => count($quantities),
            'skipped_count' => $skipped,
            'cart_totals' => $cartTotals,
        ];
    }
}
