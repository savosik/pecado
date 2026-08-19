<?php

namespace App\Contracts\Stock;

use App\Models\Product;
use App\Models\User;

interface StockServiceInterface
{
    /**
     * Get the stock information for a product for a specific user.
     * Returns array with 'available' (from primary warehouses) and 'preorder' (from preorder warehouses) quantities.
     *
     * @return array{available: int, preorder: int}
     */
    public function getStock(Product $product, ?User $user = null): array;

    /**
     * Get the available stock quantity for a product for a specific user.
     * This is the sum of stock from all primary warehouses in the user's region.
     */
    public function getAvailableStock(Product $product, ?User $user = null): int;

    /**
     * Get the preorder stock quantity for a product for a specific user.
     * This is the sum of stock from all preorder warehouses in the user's region.
     */
    public function getPreorderStock(Product $product, ?User $user = null): int;

    /**
     * Получить карту доступных остатков для коллекции товаров одним батч-запросом.
     * Ключ массива — product_id, значение — суммарное количество по primary-складам региона пользователя.
     * Товары без остатков получают 0.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function getAvailableStockMap(iterable $products, ?User $user = null): array;

    /**
     * Получить карту preorder-остатков для коллекции товаров одним батч-запросом.
     * Симметрично getAvailableStockMap, но по preorder-складам региона.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function getPreorderStockMap(iterable $products, ?User $user = null): array;

    /**
     * Вариант getStock-карт по голым product_id — для товаров из кеша (главная,
     * подборки), где моделей нет. Отдаёт обе карты одним запросом.
     *
     * @param  list<int>  $productIds
     * @return array{available: array<int, int>, preorder: array<int, int>}
     */
    public function getStockMapsByIds(array $productIds, ?User $user = null): array;

    /**
     * Склады региона пользователя. primary упорядочен по позиции в стопке
     * (для регионов без стопки — стабильный порядок по id), stack — включён
     * ли режим стопки.
     *
     * @return array{primary: list<int>, preorder: list<int>, stack: bool}
     */
    public function regionWarehouseIds(?User $user = null): array;

    /**
     * Карта выигравших складов в режиме стопки складов региона:
     * product_id → warehouse_id склада, чей остаток действует для товара
     * (null — нет в наличии ни на одном складе стопки). Для регионов без
     * стопки все значения null.
     *
     * @param  list<int>  $productIds
     * @return array<int, int|null>
     */
    public function getWinningWarehouseMap(array $productIds, ?User $user = null): array;
}
