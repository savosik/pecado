<?php

namespace App\Services\Order;

/**
 * Отказ действия над резервом/заказом клиента (v16.9.0, res-10).
 *
 * Несёт машиночитаемый код для клиентского API и русский текст для кабинета —
 * оба потребителя показывают одно и то же состояние разными словами транспорта.
 */
class ReserveActionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
