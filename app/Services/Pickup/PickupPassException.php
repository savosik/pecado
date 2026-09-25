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

    public static function allPassExists(string $code): self
    {
        return new self('all_pass_exists', "Пропуск на всё готовое уже выпущен (код {$code}). Отзовите его или выпустите пропуск на выбранные заказы.");
    }

    /** @param list<string> $numbers */
    public static function overlap(array $numbers): self
    {
        return new self('already_in_pass', 'Уже в другом пропуске: '.implode(', ', $numbers).'. Один комплект — один пропуск; отзовите тот пропуск или выберите другие заказы.');
    }

    public static function notActive(): self
    {
        return new self('not_active', 'Пропуск уже не действует');
    }
}
