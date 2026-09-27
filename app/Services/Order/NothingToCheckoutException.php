<?php

namespace App\Services\Order;

use RuntimeException;

/**
 * Оформлять нечего: корзина пуста либо в ней только предзаказ, а клиент просил «только со склада».
 */
class NothingToCheckoutException extends RuntimeException
{
    public const EMPTY_CART = 'empty_cart';

    public const PREORDER_ONLY = 'preorder_only';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self(self::EMPTY_CART, 'Корзина пуста — оформлять нечего.');
    }

    public static function preorderOnly(): self
    {
        return new self(self::PREORDER_ONLY, 'В корзине только товары под предзаказ — со склада оформлять нечего.');
    }
}
