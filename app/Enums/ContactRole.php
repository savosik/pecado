<?php

namespace App\Enums;

/**
 * Роль человека при сущности: кем он приходится партнёру, контрагенту, документу.
 *
 * Роль живёт на привязке, а не на самой карточке человека, — потому что один
 * человек бывает в разных ролях у разных сущностей. Мария Афонина — бухгалтер
 * у двух юрлиц одного партнёра, и это одна карточка с двумя привязками, а не
 * два дубля с разными телефонами.
 *
 * Роль — не украшение: получатель правила-фильтра задаётся ролью («бухгалтеру
 * этого контрагента»), и одно такое правило покрывает всю базу, подхватывая
 * нового бухгалтера само.
 *
 * Перечисление лежит в `App\Enums`, а не в `App\Enums\Crm`: его читает и кабинет
 * партнёра, где никакой CRM нет.
 */
enum ContactRole: string
{
    case DIRECTOR = 'director';
    case ACCOUNTANT = 'accountant';
    case BUYER = 'buyer';
    case LOGIST = 'logist';
    case MANAGER = 'manager';
    case OWNER = 'owner';
    case DRIVER = 'driver';
    case COURIER = 'courier';
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
            self::DRIVER => 'Водитель',
            self::COURIER => 'Курьер',
            self::OTHER => 'Прочее',
        };
    }

    /**
     * Цвет бейджа в списках (colorPalette Chakra UI).
     *
     * Группировка по смыслу: руководство — фиолетовое, деньги — синие,
     * товар и закупка — зелёные, доставка — тёплые.
     */
    public function color(): string
    {
        return match ($this) {
            self::DIRECTOR, self::OWNER => 'purple',
            self::ACCOUNTANT => 'blue',
            self::BUYER => 'green',
            self::LOGIST => 'teal',
            self::MANAGER => 'cyan',
            self::DRIVER => 'orange',
            self::COURIER => 'yellow',
            self::OTHER => 'gray',
        };
    }

    /**
     * Справочник для фильтров и селектов.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
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
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
