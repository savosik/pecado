<?php

namespace App\Contracts\Defect;

use App\Models\Product;
use App\Models\ProductDefect;

/**
 * Остаток и резерв партий некондиции.
 *
 * Резерв не хранится отдельным полем: он вычисляется по позициям заказов уценки.
 * Партия занята, пока существует неудалённый заказ type = defect на неё, поэтому
 * удаление заказа автоматически возвращает партию в продажу (Order использует SoftDeletes).
 */
interface DefectStockServiceInterface
{
    /**
     * Сколько единиц партии свободно к продаже: quantity − резерв.
     */
    public function available(ProductDefect $defect): int;

    /**
     * Карта «id партии → свободное количество» одним запросом.
     *
     * @param  iterable<ProductDefect>  $defects
     * @return array<int, int>
     */
    public function availableMap(iterable $defects): array;

    /**
     * Сколько единиц партии зарезервировано неудалёнными заказами уценки.
     */
    public function reserved(ProductDefect $defect): int;

    /**
     * Карта «id товара → есть ли у него уценка в продаже» одним запросом.
     *
     * Используется витриной для значка в списке товаров и бейджа в карточке:
     * true только если у товара есть опубликованная партия с ценой и свободным остатком.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, bool>
     */
    public function hasSellableDefectsMap(array $productIds): array;

    /**
     * Карта «id товара → минимальная цена уценки в продаже» одним запросом.
     *
     * Товары без опубликованной партии с ценой и свободным остатком в карте
     * отсутствуют, поэтому isset() по ключу эквивалентен hasSellableDefectsMap().
     * Используется витриной для надписи «Есть на уценке от X ₽».
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    public function minSellablePriceMap(array $productIds): array;

    /**
     * Карта «id товара → сводка по уценке в продаже» одним запросом.
     *
     * Нужна разделу «Уценка» витрины, где карточка кладёт в корзину не товар,
     * а конкретную партию: по количеству партий она решает, показать счётчик
     * или кнопку «Выбрать».
     *
     * Товары без партий в продаже в карте отсутствуют. Формат значения:
     *  - `count` — сколько партий доступно к покупке;
     *  - `min_price` — минимальная цена среди них;
     *  - `lot` — сама партия `['id', 'price', 'available_quantity']`, но только
     *    когда она единственная (иначе `null`: выбирать должен клиент).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{count: int, min_price: float, lot: array{id: int, price: float, available_quantity: int}|null}>
     */
    public function sellableSummaryMap(array $productIds): array;

    /**
     * Партии товара, доступные клиенту к покупке, со свободным остатком.
     *
     * @return \Illuminate\Support\Collection<int, ProductDefect>
     */
    public function sellableForProduct(Product $product): \Illuminate\Support\Collection;
}
