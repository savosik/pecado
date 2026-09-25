<?php

namespace App\Services\Pickup;

use RuntimeException;

/** Отказ в выдаче или её отмене — с машиночитаемым кодом и русским текстом для экрана склада. */
class HandoverException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyIssued(string $who, string $when): self
    {
        return new self('already_issued', "Уже выдал {$who} в {$when}");
    }

    public static function notReady(string $statusLabel): self
    {
        return new self('not_ready', "Ордер ещё не собран: статус «{$statusLabel}»");
    }

    public static function deleted(): self
    {
        return new self('deleted', 'Ордер отменён в 1С — выдавать нельзя');
    }

    public static function cancelWindowClosed(int $hours): self
    {
        return new self('cancel_window_closed', "Отменить выдачу можно только в течение {$hours} ч");
    }

    public static function reasonRequired(): self
    {
        return new self('reason_required', 'Укажите причину отмены выдачи');
    }

    public static function notIssued(): self
    {
        return new self('not_issued', 'Выдача уже отменена');
    }
}
