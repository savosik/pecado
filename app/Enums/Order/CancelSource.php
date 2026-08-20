<?php

namespace App\Enums\Order;

/**
 * Кто отменил строку заказа — метка журнала недоборов.
 *
 * 1С присылает только факт отмены (`cancelled`), причину — нет: и снятие
 * позиции складом при закрытии расходного ордера, и отказ клиента приезжают
 * одинаковым `order.updated`. Поэтому метку ставит менеджер, а сайт лишь
 * подсказывает по косвенному признаку (см. CancellationHintResolver).
 *
 * Отсутствие метки (NULL) — законное третье состояние «ещё не разобрались»,
 * а не ошибка: журнал живёт и без разметки.
 */
enum CancelSource: string
{
    case WAREHOUSE = 'warehouse';
    case CLIENT = 'client';

    public function label(): string
    {
        return match ($this) {
            self::WAREHOUSE => 'Отменено складом',
            self::CLIENT => 'Отменено клиентом',
        };
    }

    /**
     * Короткая подпись для тесной таблицы.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::WAREHOUSE => 'Склад',
            self::CLIENT => 'Клиент',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::WAREHOUSE => 'orange',
            self::CLIENT => 'purple',
        };
    }

    /**
     * @return list<array{value: string, label: string, short_label: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'short_label' => $case->shortLabel(),
            'color' => $case->color(),
        ], self::cases());
    }
}
