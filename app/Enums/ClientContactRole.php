<?php

namespace App\Enums;

/**
 * Роль контактного лица контрагента в адресной книге.
 *
 * Роль — не украшение карточки, а адресат правила: получатель уведомления может
 * быть задан не человеком, а ролью («бухгалтеру этого контрагента»). Одно такое
 * правило покрывает всю базу, и нового бухгалтера оно подхватывает само.
 *
 * Набор закрытый и намеренно короткий: это те роли, вокруг которых строятся
 * уведомления отдела — деньги, закупка, отгрузка, эскалация.
 */
enum ClientContactRole: string
{
    case DIRECTOR = 'director';
    case ACCOUNTANT = 'accountant';
    case BUYER = 'buyer';
    case LOGIST = 'logist';
    case MANAGER = 'manager';
    case OWNER = 'owner';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DIRECTOR => 'Директор',
            self::ACCOUNTANT => 'Бухгалтер',
            self::BUYER => 'Закупщик',
            self::LOGIST => 'Логист',
            self::MANAGER => 'Контактное лицо',
            self::OWNER => 'Собственник',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * Цвет бейджа в списках (colorPalette Chakra UI).
     *
     * Группировка по смыслу: деньги — синие, товар и отгрузка — зелёные,
     * руководство — фиолетовое.
     */
    public function color(): string
    {
        return match ($this) {
            self::DIRECTOR, self::OWNER => 'purple',
            self::ACCOUNTANT => 'blue',
            self::BUYER => 'green',
            self::LOGIST => 'teal',
            self::MANAGER => 'cyan',
            self::OTHER => 'gray',
        };
    }

    /**
     * Справочник для фильтров и селектов: значение → подпись.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases(),
        );
    }

    /**
     * Допустимые значения — для валидации запроса.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
