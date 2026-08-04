<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Направление звонка.
 *
 * Разделено, а не выведено из «кто первый набрал»: входящий и исходящий по-разному
 * читаются в отчёте о работе с клиентом. Десять входящих — это клиент, который сам
 * тянется; десять исходящих — менеджер, который его тянет.
 */
enum CallDirection: string
{
    use HasLabeledOptions;

    case OUTGOING = 'outgoing';
    case INCOMING = 'incoming';

    public function label(): string
    {
        return match ($this) {
            self::OUTGOING => 'Исходящий',
            self::INCOMING => 'Входящий',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OUTGOING => 'blue',
            self::INCOMING => 'green',
        };
    }
}
