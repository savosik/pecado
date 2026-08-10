<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Стадия работы с партнёром — поле сайта, о котором 1С не знает.
 *
 * Не путать со статусом лояльности (users.client_status_id): тем владеет 1С,
 * и в CRM он только читается.
 */
enum ClientLifecycleStatus: string
{
    use HasLabeledOptions;

    case LEAD = 'lead';
    case IN_WORK = 'in_work';
    case ACTIVE = 'active';
    case SLEEPING = 'sleeping';
    case CLOSING = 'closing';
    case CHURNED = 'churned';
    case HOPELESS = 'hopeless';

    public function label(): string
    {
        return match ($this) {
            self::LEAD => 'Лид',
            self::IN_WORK => 'В работе',
            self::ACTIVE => 'Активен',
            self::SLEEPING => 'Спящий',
            self::CLOSING => 'Закрывается',
            self::CHURNED => 'Ушёл',
            self::HOPELESS => 'Непреодолимо',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::LEAD => 'purple',
            self::IN_WORK => 'blue',
            self::ACTIVE => 'green',
            self::SLEEPING => 'orange',
            self::CLOSING => 'red',
            self::CHURNED => 'gray',
            self::HOPELESS => 'gray',
        };
    }

    /**
     * Варианты для фронта вместе с цветом бейджа.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function optionsWithColor(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases(),
        );
    }

    /**
     * Стадии, на которых партнёр считается работающим.
     *
     * По ним ночная команда ищет тех, кто похож на спящего: у лида отгрузок
     * не было никогда, а ушедшего незачем возвращать в «спящие».
     *
     * @return list<self>
     */
    public static function workingStates(): array
    {
        return [self::IN_WORK, self::ACTIVE];
    }
}
