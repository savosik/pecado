<?php

namespace App\Services\Assistant;

use RuntimeException;

/**
 * Ход в тред сейчас невозможен: закрыт, занят, квота, помощник недоступен.
 * Код — для фронта, сообщение — для человека, по-русски.
 */
final class ThreadRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
