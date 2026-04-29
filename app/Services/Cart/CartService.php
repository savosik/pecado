<?php

namespace App\Services\Cart;

use App\Contracts\Cart\CartServiceInterface;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
        $agg = $this->computeCartAggregates($cart, $user);

        return [
            'total_price' => $agg['total_amount_discounted'],
            'items_count' => $agg['total_quantity'],
            'available_count' => $agg['available_count'],
            'preorder_count' => $agg['preorder_count'],
        ];
    }

    /**
     * Get summary information for multiple carts.
     *
     * @param  Collection<int, Cart>  $carts
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

        if (! empty($itemIds)) {
            $itemsQuery->whereIn('product_id', $itemIds);
        }

        $itemsToMove = $itemsQuery->get();

        foreach ($itemsToMove as $item) {
            $existingItem = $targetCart->items()
                ->where('product_id', $item->product_id)
                ->where('item_type', $item->item_type)
                ->where('warehouse_id', $item->warehouse_id)
                ->first();

            if ($existingItem) {
                $existingItem->update([
                    'quantity' => $existingItem->quantity + $item->quantity,
                ]);
                $item->delete();
            } else {
                $item->update([
                    'cart_id' => $targetCart->id,
                ]);
            }
        }
    }

    /**
     * Get or create the active cart for a user.
     */
    public function getOrCreateActiveCart(User $user): Cart
    {
        $cart = $user->carts()->active()->first();

        if (! $cart) {
            $cart = $user->carts()->create([
                'name' => 'Корзина',
                'is_active' => true,
            ]);
        }

        return $cart;
    }

    /**
     * Add a product to the cart.
     * Calculates current quantity + qty and delegates to setProductQuantity.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function addProduct(User $user, Cart $cart, Product $product, int $qty): array
    {
        $currentQty = $cart->items()
            ->where('product_id', $product->id)
            ->sum('quantity');

        $newTotalQty = $currentQty + $qty;

        return $this->setProductQuantity($user, $cart, $product, $newTotalQty);
    }

    /**
     * Update cart item quantity with spillover logic.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function updateItemQuantity(User $user, CartItem $item, int $qty): array
    {
        if ($item->cart->user_id !== $user->id) {
            throw new \LogicException('Доступ запрещён.');
        }

        $cart = $item->cart;
        $product = $item->product;

        return $this->setProductQuantity($user, $cart, $product, $qty);
    }

    /**
     * Remove a cart item.
     */
    public function removeItem(User $user, CartItem $item): void
    {
        if ($item->cart->user_id !== $user->id) {
            throw new \LogicException('Доступ запрещён.');
        }

        $item->delete();
    }

    /**
     * Set exact product quantity with spillover logic.
     * Main API method for frontend.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function setProductQuantity(User $user, Cart $cart, Product $product, int $qty): array
    {
        $stock = $this->stockService->getStock($product, $user);
        $maxTotal = $stock['available'] + $stock['preorder'];

        // If qty is 0, remove all items for this product
        if ($qty <= 0) {
            $cart->items()->where('product_id', $product->id)->delete();

            return [
                'instock' => 0,
                'preorder' => 0,
                'clamped' => 0,
                'max_total' => $maxTotal,
                'cart_totals' => $this->buildCartTotals($cart, $user),
            ];
        }

        $clamped = min($qty, $maxTotal);
        $instockQty = min($clamped, $stock['available']);
        $preorderQty = $clamped - $instockQty;

        $price = $this->priceService->getUserPrice($product, $user);

        DB::transaction(function () use ($cart, $product, $instockQty, $preorderQty, $price) {
            // Lock existing rows to prevent race conditions
            $cart->items()->where('product_id', $product->id)->lockForUpdate()->get();
            $cart->items()->where('product_id', $product->id)->delete();

            if ($instockQty > 0) {
                $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $instockQty,
                    'price' => $price,
                    'item_type' => 'instock',
                    'warehouse_id' => null,
                ]);
            }

            if ($preorderQty > 0) {
                $cart->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $preorderQty,
                    'price' => $price,
                    'item_type' => 'preorder',
                    'warehouse_id' => null,
                ]);
            }
        });

        // Refresh to get updated totals (eager-load product for buildCartTotals)
        $cart->load('items.product');

        return [
            'instock' => $instockQty,
            'preorder' => $preorderQty,
            'clamped' => $clamped,
            'max_total' => $maxTotal,
            'cart_totals' => $this->buildCartTotals($cart, $user),
        ];
    }

    /**
     * Create a new cart for a user (deactivates other carts).
     */
    public function createCart(User $user, ?string $name = null): Cart
    {
        $user->carts()->update(['is_active' => false]);

        return $user->carts()->create([
            'name' => $name ?: 'Корзина',
            'is_active' => true,
        ]);
    }

    /**
     * Switch the active cart for a user.
     */
    public function switchActiveCart(User $user, Cart $cart): void
    {
        $user->carts()->update(['is_active' => false]);
        $cart->update(['is_active' => true]);
    }

    /**
     * Rename a cart.
     */
    public function renameCart(User $user, Cart $cart, string $name): void
    {
        $cart->update(['name' => $name]);
    }

    /**
     * Delete a cart. Cannot delete the last cart.
     * If the deleted cart was active, the next one becomes active.
     */
    public function deleteCart(User $user, Cart $cart): void
    {
        $cartsCount = $user->carts()->count();

        if ($cartsCount <= 1) {
            throw new \LogicException('Нельзя удалить последнюю корзину.');
        }

        $wasActive = $cart->is_active;
        $cart->items()->delete();
        $cart->delete();

        if ($wasActive) {
            $nextCart = $user->carts()->orderBy('id')->first();
            if ($nextCart) {
                $nextCart->update(['is_active' => true]);
            }
        }
    }

    /**
     * Get active cart quantities map: {productId: totalQty}.
     *
     * @return array<int, int>
     */
    public function getActiveQuantities(User $user): array
    {
        $cart = $this->getOrCreateActiveCart($user);

        return $cart->items()
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->toArray();
    }

    /**
     * Get cart items summary for API.
     */
    public function getCartItemsSummary(Cart $cart): array
    {
        $items = $cart->items()
            ->select('id', 'product_id', 'quantity', 'item_type')
            ->get();

        return [
            'items' => $items,
            'total_quantity' => $items->sum('quantity'),
        ];
    }

    /**
     * Get detailed cart data for Inertia page.
     */
    public function getCartDetails(Cart $cart, User $user): array
    {
        $cart->loadMissing('items.product.brand', 'items.product.barcodes', 'items.product.media');

        $items = $cart->items->map(function (CartItem $item) use ($user) {
            $product = $item->product;

            if (! $product) {
                return null;
            }

            $basePrice = $this->priceService->getBasePriceForUser($product, $user);
            $userPrice = $this->priceService->getUserPrice($product, $user);
            $stock = $this->stockService->getStock($product, $user);

            return [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'product' => [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'code' => $product->code,
                    'thumbnail_url' => $product->getFirstMediaUrl('main', 'thumb') ?: null,
                    'main_image_url' => $product->getFirstMediaUrl('main') ?: null,
                    'brand' => $product->brand ? [
                        'id' => $product->brand->id,
                        'name' => $product->brand->name,
                        'slug' => $product->brand->slug,
                    ] : null,
                    'barcodes' => $product->barcodes->pluck('barcode')->toArray(),
                ],
                'price' => $item->price ?? 0,
                'price_regular' => $basePrice,
                'price_discounted' => $userPrice,
                'item_type' => $item->item_type,
                'is_unavailable' => ($stock['available'] + $stock['preorder']) <= 0,
                'available_quantity' => $stock['available'],
                'preorder_quantity' => $stock['preorder'],
                'max_total' => $stock['available'] + $stock['preorder'],
                'total_amount' => round($item->quantity * ($item->price ?? 0), 2),
                'total_amount_regular' => round($item->quantity * $basePrice, 2),
                'total_amount_discounted' => round($item->quantity * $userPrice, 2),
            ];
        })->filter()->values()->toArray();

        return [
            'items' => $items,
            'total_quantity' => $cart->total_quantity,
            'instock_quantity' => $cart->instock_quantity,
            'preorder_quantity' => $cart->preorder_quantity,
            'total_amount_regular' => array_sum(array_column($items, 'total_amount_regular')),
            'total_amount_discounted' => array_sum(array_column($items, 'total_amount_discounted')),
        ];
    }

    // ────────────────────────────────────────────
    // Private
    // ────────────────────────────────────────────

    /**
     * Compute all cart aggregates in a single pass.
     * Used by getCartSummary and buildCartTotals to avoid duplication.
     */
    private function computeCartAggregates(Cart $cart, User $user): array
    {
        $cart->loadMissing('items.product');

        $totalQuantity = 0;
        $availableCount = 0;
        $preorderCount = 0;
        $totalAmountRegular = 0.0;
        $totalAmountDiscounted = 0.0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $qty = $item->quantity;
            $totalQuantity += $qty;

            if ($item->isInstock()) {
                $availableCount += $qty;
            } else {
                $preorderCount += $qty;
            }

            $totalAmountRegular += $qty * $this->priceService->getBasePriceForUser($product, $user);
            $totalAmountDiscounted += $qty * $this->priceService->getUserPrice($product, $user);
        }

        return [
            'total_quantity' => $totalQuantity,
            'available_count' => $availableCount,
            'preorder_count' => $preorderCount,
            'total_amount_regular' => round($totalAmountRegular, 2),
            'total_amount_discounted' => round($totalAmountDiscounted, 2),
        ];
    }

    /**
     * Build cart totals summary.
     */
    protected function buildCartTotals(Cart $cart, User $user): array
    {
        $agg = $this->computeCartAggregates($cart, $user);

        return [
            'total_quantity' => $agg['total_quantity'],
            'total_amount_regular' => $agg['total_amount_regular'],
            'total_amount_discounted' => $agg['total_amount_discounted'],
        ];
    }
}
