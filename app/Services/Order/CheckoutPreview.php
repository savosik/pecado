<?php

namespace App\Services\Order;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\PromoKind;
use App\Models\Cart;
use App\Models\User;

/**
 * Сборка чекаута до оформления: группы строк (наличие / предзаказ / уценка /
 * промо / образцы) и подытоги — как на странице оформления в кабинете.
 */
class CheckoutPreview
{
    public function __construct(private readonly CartServiceInterface $carts) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Cart $cart): array
    {
        $details = $this->carts->getCartDetails($cart, $user);
        $items = $details['items'] ?? [];

        $instock = array_values(array_filter($items, fn ($it) => ($it['item_type'] ?? '') === 'instock'));
        $preorder = array_values(array_filter($items, fn ($it) => ($it['item_type'] ?? '') === 'preorder'));
        $defect = array_values(array_filter($items, fn ($it) => ($it['item_type'] ?? '') === 'defect'));

        // Промо-позиции: подотчётные и рекламные образцы показываются разными
        // таблицами — у образцов другой режим учёта, и клиент должен видеть это
        // до оформления, а не узнавать из накладной.
        $promo = array_values(array_filter(
            $details['promo_items'] ?? [],
            fn ($it) => ($it['promo_kind'] ?? '') !== PromoKind::SAMPLE->value && ! ($it['is_declined'] ?? false),
        ));
        $samples = array_values(array_filter(
            $details['promo_items'] ?? [],
            fn ($it) => ($it['promo_kind'] ?? '') === PromoKind::SAMPLE->value && ! ($it['is_declined'] ?? false),
        ));

        return [
            'cart' => ['id' => $cart->id, 'name' => $cart->name],
            'instock_items' => $instock,
            'preorder_items' => $preorder,
            'defect_items' => $defect,
            'promo_items' => $promo,
            'sample_items' => $samples,
            'instock_totals' => $this->totals($instock),
            'preorder_totals' => $this->totals($preorder),
            'defect_totals' => $this->totals($defect),
            'promo_totals' => $this->totals($promo),
            'sample_totals' => $this->totals($samples),
            'grand_total' => [
                'quantity' => $details['total_quantity'] ?? 0,
                'amount_regular' => $details['total_amount_regular'] ?? 0,
                'amount_discounted' => $details['total_amount_discounted'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{quantity: int, amount_regular: float, amount_discounted: float}
     */
    public function totals(array $items): array
    {
        return [
            'quantity' => array_sum(array_column($items, 'quantity')),
            'amount_regular' => array_sum(array_column($items, 'total_amount_regular')),
            'amount_discounted' => array_sum(array_column($items, 'total_amount_discounted')),
        ];
    }
}
