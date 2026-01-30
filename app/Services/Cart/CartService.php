<?php

namespace App\Services\Cart;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class CartService implements CartServiceInterface
{
    public function __construct(
        protected PriceServiceInterface $priceService,
        protected StockServiceInterface $stockService
    ) {}

    /**
     * Get summary information for a cart.
     *
     * @return array{total_price: float, items_count: int, available_count: int, preorder_count: int}
     */
    public function getCartSummary(Cart $cart, User $user): array
    {
        $cart->loadMissing('items.product');

        $totalPrice = 0.0;
        $itemsCount = 0;
        $availableCount = 0;
        $preorderCount = 0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            $quantity = $item->quantity;

            $itemsCount += $quantity;

            // Calculate price in user's currency
            $unitPrice = $this->priceService->getUserPrice($product, $user);
            $totalPrice += $unitPrice * $quantity;

            // Calculate stock availability
            $stock = $this->stockService->getStock($product, $user);

            // Determine how much of the quantity is available vs preorder
            $availableForItem = min($quantity, $stock['available']);
            $remainingQuantity = $quantity - $availableForItem;
            $preorderForItem = min($remainingQuantity, $stock['preorder']);

            $availableCount += $availableForItem;
            $preorderCount += $preorderForItem;
        }

        return [
            'total_price' => round($totalPrice, 2),
            'items_count' => $itemsCount,
            'available_count' => $availableCount,
            'preorder_count' => $preorderCount,
        ];
    }

    /**
     * Get summary information for multiple carts.
     *
     * @param Collection<int, Cart> $carts
     * @return array<int, array{total_price: float, items_count: int, available_count: int, preorder_count: int}>
     */
    public function getCartsSummary(Collection $carts, User $user): array
    {
        $summaries = [];

        foreach ($carts as $cart) {
            $summaries[$cart->id] = $this->getCartSummary($cart, $user);
        }

        return $summaries;
    }

    public function moveItems(Cart $sourceCart, Cart $targetCart, array $itemIds = []): void
    {
        $itemsQuery = $sourceCart->items();

        if (!empty($itemIds)) {
            $itemsQuery->whereIn('product_id', $itemIds);
        }

        $itemsToMove = $itemsQuery->get();

        foreach ($itemsToMove as $item) {
            // Check if item already exists in the target cart
            $existingItem = $targetCart->items()
                ->where('product_id', $item->product_id)
                ->first();

            if ($existingItem) {
                // Update quantity and delete from source
                $existingItem->update([
                    'quantity' => $existingItem->quantity + $item->quantity,
                ]);
                $item->delete();
            } else {
                // Move item to target cart
                $item->update([
                    'cart_id' => $targetCart->id,
                ]);
            }
        }
    }
}
