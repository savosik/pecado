<?php

namespace App\Enums\Shortage;

/**
 * Категория причины недобора — зона, в которой заказ развалился.
 *
 * Сами причины ведёт РОП в справочнике: их много, формулировки меняются,
 * и держать их в коде бессмысленно. Категорий, наоборот, ровно шесть, и они
 * заданы здесь намеренно: на них держится вся сводная часть раздела — чипы,
 * цвета, легенда, разбор «чья это зона». Свободные категории быстро развели бы
 * синонимы («склад», «Склад», «складская»), и сводка перестала бы сходиться.
 *
 * Категория отвечает на вопрос «кто мог это предотвратить», а не «кто нажал
 * кнопку»: отмену в 1С почти всегда делает менеджер, но причина при этом
 * складская, снабженческая или клиентская.
 */
enum ShortageReasonCategory: string
{
    case STOCK = 'stock';
    case SUPPLY = 'supply';
    case WAREHOUSE = 'warehouse';
    case CLIENT = 'client';
    case MANAGER = 'manager';
    case ACCOUNTING = 'accounting';

    public function label(): string
    {
        return match ($this) {
            self::STOCK => 'Остатки и резерв',
            self::SUPPLY => 'Снабжение',
            self::WAREHOUSE => 'Склад',
            self::CLIENT => 'Клиент',
            self::MANAGER => 'Менеджер',
            self::ACCOUNTING => 'Учёт',
        };
    }

    /**
     * Пояснение для легенды: чем эта категория отличается от соседних.
     */
    public function description(): string
    {
        return match ($this) {
            self::STOCK => 'Товара не оказалось в наличии к моменту сборки: остаток сайта разошёлся или позицию увели из-под резерва.',
            self::SUPPLY => 'Товар не был обеспечен: поставка не заказана у поставщика или не пришла в срок.',
            self::WAREHOUSE => 'Позицию снял склад при сборке: недостача при пересчёте или брак упаковки.',
            self::CLIENT => 'От позиции отказался сам клиент — до сборки через менеджера или после неё.',
            self::MANAGER => 'Позицию снял менеджер по собственному решению, без просьбы клиента.',
            self::ACCOUNTING => 'Расхождение в учёте 1С: строка отменена из-за ошибки данных, а не из-за товара.',
        };
    }

    /**
     * Цвет чипа и бейджа (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::STOCK => 'red',
            self::SUPPLY => 'orange',
            self::WAREHOUSE => 'yellow',
            self::CLIENT => 'purple',
            self::MANAGER => 'blue',
            self::ACCOUNTING => 'teal',
        };
    }

    /**
     * Порядок категорий в чипах и легенде: от «товара не было» к «ошибке в данных».
     */
    public function position(): int
    {
        return match ($this) {
            self::STOCK => 1,
            self::SUPPLY => 2,
            self::WAREHOUSE => 3,
            self::CLIENT => 4,
            self::MANAGER => 5,
            self::ACCOUNTING => 6,
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->position() <=> $b->position());

        return $cases;
    }

    /**
     * @return list<array{value: string, label: string, description: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'color' => $case->color(),
        ], self::ordered());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
