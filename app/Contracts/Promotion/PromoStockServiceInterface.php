<?php

namespace App\Contracts\Promotion;

use App\Models\Product;
use App\Models\User;

/**
 * Сколько промо-позиций можно выдать.
 *
 * Отдельного учёта фонда нет — доступность определяется остатком складов региона
 * минус то, что уже обещано незакрытыми промо-заказами. Резерв не хранится полем:
 * он вычисляется по позициям заказов, поэтому удаление заказа само возвращает
 * товар в фонд и рассинхрону взяться неоткуда.
 */
interface PromoStockServiceInterface
{
    /**
     * Сколько единиц товара свободно под промо: остаток региона − резерв.
     */
    public function available(Product $product, ?User $user = null): int;

    /**
     * Карта «id товара → свободное количество» фиксированным числом запросов.
     *
     * @param  iterable<Product>  $products
     * @return array<int, int>
     */
    public function availableMap(iterable $products, ?User $user = null): array;

    /**
     * Сколько единиц товара обещано незакрытыми промо-заказами.
     */
    public function reserved(Product $product): int;

    /**
     * Карта «id товара → зарезервированное количество» одним запросом.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function reservedMap(array $productIds): array;
}
