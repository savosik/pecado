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

    /**
     * Подотчётные промо-позиции: подарки и товары по промо-цене, выданные акцией.
     * Отгружаются с обычного склада региона, но отдельным документом.
     */
    case PROMO = 'promo';

    /**
     * Рекламные образцы (пробники): в накладную клиенту не входят,
     * отгружаются со склада «Москва реклама» (появится в волне 3).
     */
    case PROMO_SAMPLE = 'promo_sample';

    /**
     * Типы, которых ещё нет в интерфейсе и в контракте 1С.
     *
     * Кейсы объявлены, потому что без них не живёт ни один Eloquent-путь
     * (каст `Order::$casts['type']` падает на неизвестном значении), но заказы
     * этих типов пока не создаются: выдача — карточка promo-08, контракт с 1С
     * и подписи в интерфейсах — promo-09 и promo-10. До тех пор они не должны
     * появляться в фильтрах, иначе пользователь увидит выбор, который ничего
     * не находит.
     */
    private const UNRELEASED = [self::PROMO, self::PROMO_SAMPLE];

    public function label(): string
    {
        return match ($this) {
            self::ORDER => 'Заказ со склада',
            self::PREORDER => 'Предзаказ',
            self::DEFECT => 'Уценка',
            self::PROMO => 'Промо-позиции',
            self::PROMO_SAMPLE => 'Рекламные образцы',
        };
    }

    /**
     * Выпущен ли тип в интерфейс.
     */
    public function isReleased(): bool
    {
        return ! in_array($this, self::UNRELEASED, true);
    }

    /**
     * Справочник для фильтров и селектов: значение → подпись.
     *
     * Невыпущенные типы по умолчанию не отдаются — см. `UNRELEASED`.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(bool $includeUnreleased = false): array
    {
        return array_values(array_map(
            static fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            array_filter(
                self::cases(),
                static fn (self $case) => $includeUnreleased || $case->isReleased(),
            ),
        ));
    }
}
