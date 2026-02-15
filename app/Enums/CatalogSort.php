<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * Варианты сортировки каталога товаров.
 *
 * Используется в ProductFilterRequest и CatalogApiController.
 */
enum CatalogSort: string
{
    case Newest = 'newest';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case NameAsc = 'name_asc';
    case NameDesc = 'name_desc';

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
            self::Newest => $query->orderByDesc('created_at')->orderByDesc('id'),
            self::PriceAsc => $query->orderBy('base_price')->orderByDesc('id'),
            self::PriceDesc => $query->orderByDesc('base_price')->orderByDesc('id'),
            self::NameAsc => $query->orderBy('name')->orderByDesc('id'),
            self::NameDesc => $query->orderByDesc('name')->orderByDesc('id'),
        };
    }

    /**
     * Русское название для отображения на фронтенде.
     */
    public function label(): string
    {
        return match ($this) {
            self::Newest => 'Новинки',
            self::PriceAsc => 'Сначала дешёвые',
            self::PriceDesc => 'Сначала дорогие',
            self::NameAsc => 'По имени А–Я',
            self::NameDesc => 'По имени Я–А',
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
