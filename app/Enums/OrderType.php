<?php

namespace App\Enums;

enum OrderType: string
{
    case ORDER = 'order';
    case PREORDER = 'preorder';

    /**
     * Заказ уценки: отгружается со склада некондиции, цены взяты из партий
     * (product_defects), а не из прайса. См. docs-erp/content/rules/orders.md.
     */
    case DEFECT = 'defect';

    public function label(): string
    {
        return match ($this) {
            self::ORDER => 'Заказ со склада',
            self::PREORDER => 'Предзаказ',
            self::DEFECT => 'Уценка',
        };
    }

    /**
     * Справочник для фильтров и селектов: значение → подпись.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
