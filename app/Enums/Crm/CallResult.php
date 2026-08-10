<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Чем закончился звонок.
 *
 * «Не дозвонился» отделён от «поговорили» намеренно: попытка — это тоже работа
 * с партнёром, и в ленте она должна быть видна. Без этого менеджер, честно
 * набиравший пять раз, выглядит так же, как тот, кто не набирал вовсе.
 */
enum CallResult: string
{
    use HasLabeledOptions;

    case TALKED = 'talked';
    case NO_ANSWER = 'no_answer';
    case BUSY = 'busy';
    case WRONG_NUMBER = 'wrong_number';
    case CALLBACK = 'callback';

    public function label(): string
    {
        return match ($this) {
            self::TALKED => 'Поговорили',
            self::NO_ANSWER => 'Не ответил',
            self::BUSY => 'Занято',
            self::WRONG_NUMBER => 'Неверный номер',
            self::CALLBACK => 'Просили перезвонить',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TALKED => 'green',
            self::NO_ANSWER, self::BUSY => 'orange',
            self::WRONG_NUMBER => 'red',
            self::CALLBACK => 'purple',
        };
    }

    /**
     * Разговор состоялся.
     *
     * По этому признаку отличаются «контакт был» и «попытка была» — в покрытии
     * партнёров это разные события.
     */
    public function isConversation(): bool
    {
        return $this === self::TALKED || $this === self::CALLBACK;
    }
}
