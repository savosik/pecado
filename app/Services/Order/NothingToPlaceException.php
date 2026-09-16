<?php

namespace App\Services\Order;

use RuntimeException;

/**
 * Ни одна позиция запроса не может быть заказана: товары не найдены или их нет.
 *
 * @param  list<array<string, mixed>>  $notAccepted
 */
class NothingToPlaceException extends RuntimeException
{
    public function __construct(public readonly array $notAccepted)
    {
        parent::__construct('Ни одна из позиций недоступна для заказа');
    }
}
