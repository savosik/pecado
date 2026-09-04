<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Варианты сортировки каталога товаров.
 *
 * Используется в ProductFilterRequest и CatalogApiController.
 *
 * ВАЖНО: сортировки `default` и `newest` раскладывают выдачу по «полкам»
 * наличия и опираются на алиасы `primary_stock` / `preorder_stock` —
 * подзапросы остатков региона. Вызывающий обязан добавить их к запросу
 * (`ProductQueryService::withRegionStockSums()` после `select('products.*')`),
 * иначе SQL упадёт на неизвестной колонке. Так делают все три потребителя:
 * каталог, поиск и контентное API.
 */
enum CatalogSort: string
{
    case Default = 'default';
    case Newest = 'newest';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case NameAsc = 'name_asc';
    case NameDesc = 'name_desc';
    case ArticleAsc = 'article_asc';
    case ArticleDesc = 'article_desc';

    /**
     * Полка наличия: в наличии → предзаказ → всё остальное.
     *
     * Живая, по остаткам в момент запроса, а не по снимку ночного пересчёта:
     * товар, приехавший на склад утром, обязан подняться сразу же.
     */
    public const AVAILABILITY_TIER = '(CASE WHEN primary_stock > 0 THEN 0 WHEN preorder_stock > 0 THEN 1 ELSE 2 END)';

    /**
     * Товар без цены купить нельзя — внутри своей полки он уходит вниз.
     * Нулевая цена у нас означает «цена из 1С не пришла», а не «бесплатно».
     */
    public const PRICE_TIER = '(CASE WHEN products.base_price > 0 THEN 0 ELSE 1 END)';

    /**
     * Применить сортировку к запросу.
     *
     * Сбрасывает текущую сортировку и устанавливает новую.
     * Всегда добавляет вторичную сортировку по id desc для стабильности.
     */
    public function apply(Builder $query): Builder
    {
        $query->reorder();

        return match ($this) {
            // Витрина по умолчанию: сначала полка наличия, внутри неё —
            // товары с ценой, дальше балл (catalog:rebuild-sort-scores).
            // Товары без продаж имеют балл 0 и выстраиваются по id — то есть
            // новинки среди «нулевых» оказываются выше старых залежей.
            self::Default => $query
                ->orderByRaw(self::AVAILABILITY_TIER)
                ->orderByRaw(self::PRICE_TIER)
                ->orderByDesc('products.sort_score')
                ->orderByDesc('id'),
            // «Новинки»: сначала новинки в наличии, затем остальное наличие
            // по дате, затем предзаказ и то, чего нет. Без полок сюда лезли
            // предзаказные позиции и товары с нулевой ценой — они по дате
            // создания как раз самые свежие.
            self::Newest => $query
                ->orderByRaw(
                    '(CASE WHEN primary_stock > 0 AND products.is_new = 1 THEN 0'
                    .' WHEN primary_stock > 0 THEN 1'
                    .' WHEN preorder_stock > 0 THEN 2 ELSE 3 END)'
                )
                ->orderByRaw(self::PRICE_TIER)
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            self::PriceAsc => $query->orderBy('base_price')->orderByDesc('id'),
            self::PriceDesc => $query->orderByDesc('base_price')->orderByDesc('id'),
            self::NameAsc => $query->orderBy('name')->orderByDesc('id'),
            self::NameDesc => $query->orderByDesc('name')->orderByDesc('id'),
            self::ArticleAsc => $query->orderBy('sku')->orderByDesc('id'),
            self::ArticleDesc => $query->orderByDesc('sku')->orderByDesc('id'),
        };
    }

    /**
     * Русское название для отображения на фронтенде.
     */
    public function label(): string
    {
        return match ($this) {
            self::Default => 'По умолчанию',
            self::Newest => 'Новинки',
            self::PriceAsc => 'Сначала дешёвые',
            self::PriceDesc => 'Сначала дорогие',
            self::NameAsc => 'По имени А–Я',
            self::NameDesc => 'По имени Я–А',
            self::ArticleAsc => 'Артикул А–Я',
            self::ArticleDesc => 'Артикул Я–А',
        };
    }

    /**
     * Массив опций для передачи на фронтенд (select).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
