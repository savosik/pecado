<?php

namespace App\Enums;

/**
 * Стадия исполнения заказа глазами клиента (pick-03).
 *
 * Поверх десяти статусов заказа 1С и семи статусов расходного ордера: клиенту нужен один ответ
 * «что с моим заказом». Нигде не хранится — считается на лету (`OrderFulfilmentResolver`),
 * потому что статус «отгружен» у расходного ордера обратим.
 */
enum OrderFulfilmentStage: string
{
    case NONE = 'none';
    case RESERVED = 'reserved';
    case SENT_TO_WAREHOUSE = 'sent_to_warehouse';
    case PICKING = 'picking';
    case READY = 'ready';
    case HANDED_OVER = 'handed_over';
    case SHIPPED = 'shipped';

    public function label(): string
    {
        return match ($this) {
            self::NONE => '',
            self::RESERVED => 'В резерве',
            self::SENT_TO_WAREHOUSE => 'Передан на склад',
            self::PICKING => 'Собирается',
            self::READY => 'Собран, ждёт выдачи',
            self::HANDED_OVER => 'Выдан курьеру',
            self::SHIPPED => 'Отгружен',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NONE, self::SENT_TO_WAREHOUSE => 'gray',
            self::RESERVED => 'purple',
            self::PICKING => 'orange',
            self::READY => 'green',
            self::HANDED_OVER, self::SHIPPED => 'teal',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::NONE => '',
            self::RESERVED => 'Товар закреплён за вами и ждёт вашего решения',
            self::SENT_TO_WAREHOUSE => 'Склад получил заказ и возьмёт его в сборку',
            self::PICKING => 'Склад собирает заказ, обычно это 30–40 минут',
            self::READY => 'Можно отправлять курьера',
            self::HANDED_OVER => 'Заказ выдан на складе',
            self::SHIPPED => 'Заказ собран и отгружен',
        };
    }

    /** Шаг на шкале прогресса 1…4 (0 — шкалу не показываем). */
    public function step(): int
    {
        return match ($this) {
            self::NONE, self::RESERVED => 0,
            self::SENT_TO_WAREHOUSE => 1,
            self::PICKING => 2,
            self::READY, self::SHIPPED => 3,
            self::HANDED_OVER => 4,
        };
    }
}
