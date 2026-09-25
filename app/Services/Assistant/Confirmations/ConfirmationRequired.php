<?php

namespace App\Services\Assistant\Confirmations;

use App\Models\ChatConfirmation;
use RuntimeException;

/**
 * Необратимая операция из чата-помощника ждёт кнопки клиента.
 *
 * Бросается из раннера операций, когда вызов пришёл с токеном вида
 * `assistant`, а одобренного подтверждения с такими аргументами нет: запись
 * создана (или уже ждёт), клиент видит карточку, модель получает код
 * `confirmation_required` и просит нажать «Подтвердить».
 */
final class ConfirmationRequired extends RuntimeException
{
    public function __construct(public readonly ChatConfirmation $confirmation)
    {
        parent::__construct(
            'Действие ждёт подтверждения клиента (карточка #'.$confirmation->id.' в чате). '
            .'Перечислите клиенту, что будет сделано, и попросите нажать «Подтвердить». '
            .'После сообщения «Клиент подтвердил действие» вызовите операцию ещё раз с теми же аргументами.'
        );
    }
}
