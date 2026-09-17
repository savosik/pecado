<?php

namespace App\Services\Pickup;

use RuntimeException;

/** Отказ в выпуске или отзыве пропуска: машиночитаемый код + русский текст. */
class PickupPassException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function nothingReady(): self
    {
        return new self('nothing_ready', 'Сейчас нет собранных заказов, которые можно забрать');
    }

    public static function notYours(): self
    {
        return new self('not_available', 'Часть выбранных заказов недоступна для выдачи: обновите страницу');
    }

    public static function notActive(): self
    {
        return new self('not_active', 'Пропуск уже не действует');
    }
}
