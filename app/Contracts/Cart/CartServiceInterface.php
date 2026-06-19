<?php

namespace App\Contracts\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface CartServiceInterface
{
    /**
     * Get summary information for a cart.
     *
     * @return array{total_price: float, items_count: int, available_count: int, preorder_count: int}
     */
    public function getCartSummary(Cart $cart, User $user): array;

    /**
     * Get summary information for multiple carts.
     *
     * @param  Collection<int, Cart>  $carts
     * @return array<int, array{total_price: float, items_count: int, available_count: int, preorder_count: int}>
     */
    public function getCartsSummary(Collection $carts, User $user): array;

    /**
     * Move items between carts.
     */
    public function moveItems(Cart $sourceCart, Cart $targetCart, array $itemIds = []): void;

    /**
     * Get or create the active cart for a user.
     */
    public function getOrCreateActiveCart(User $user): Cart;

    /**
     * Add a product to the cart with spillover logic.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function addProduct(User $user, Cart $cart, Product $product, int $qty): array;

    /**
     * Update cart item quantity with spillover logic.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function updateItemQuantity(User $user, CartItem $item, int $qty): array;

    /**
     * Remove a cart item.
     */
    public function removeItem(User $user, CartItem $item): void;

    /**
     * Set exact product quantity in cart with spillover logic.
     * Main API method for frontend.
     *
     * @return array{instock: int, preorder: int, clamped: int, max_total: int, cart_totals: array}
     */
    public function setProductQuantity(User $user, Cart $cart, Product $product, int $qty): array;

    /**
     * Bulk-вариант setProductQuantity. Обрабатывает массив пар (product_id, qty)
     * в одной транзакции и возвращает сводку по каждому товару + актуальные
     * cart_totals. Используется для массовых действий («Задать количество»,
     * «Удалить выбранные»), чтобы избежать N параллельных POST → дедлоков на
     * cart_items одной корзины.
     *
     * @param  array<int,int>  $quantities  карта product_id → qty (qty<=0 удаляет товар)
     * @return array{
     *     items: array<int, array{product_id:int, instock:int, preorder:int, clamped:int, max_total:int}>,
     *     cart_totals: array
     * }
     */
    public function setProductsQuantity(User $user, Cart $cart, array $quantities): array;

    /**
     * Create a new cart for a user (deactivates other carts).
     */
    public function createCart(User $user, ?string $name = null): Cart;

    /**
     * Switch the active cart for a user.
     */
    public function switchActiveCart(User $user, Cart $cart): void;

    /**
     * Rename a cart.
     */
    public function renameCart(User $user, Cart $cart, string $name): void;

    /**
     * Delete a cart. Cannot delete the last cart.
     * If the deleted cart was active, the next one becomes active.
     */
    public function deleteCart(User $user, Cart $cart): void;

    /**
     * Get active cart quantities map: {productId: totalQty}.
     *
     * @return array<int, int>
     */
    public function getActiveQuantities(User $user): array;

    /**
     * Quantities of a single product across all of the user's carts.
     *
     * @return array{
     *     max_total: int,
     *     available: int,
     *     preorder: int,
     *     carts: array<int, array{id:int, name:string, is_active:bool, quantity:int, instock:int, preorder:int}>
     * }
     */
    public function getProductQuantitiesAcrossCarts(User $user, Product $product): array;

    /**
     * Get cart items summary for API.
     */
    public function getCartItemsSummary(Cart $cart): array;

    /**
     * Get detailed cart data for Inertia page.
     */
    public function getCartDetails(Cart $cart, User $user): array;
}
