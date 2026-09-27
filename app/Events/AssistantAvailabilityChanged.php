<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Помощник клиента пропал или вернулся. Слушатель шлёт РОПу письмо — не
 * чаще раза в час, чтобы пополнение баланса не зависело от того, заметил ли
 * кто-то пропажу иконки.
 */
class AssistantAvailabilityChanged
{
    use Dispatchable;

    public function __construct(
        public readonly bool $available,
        public readonly ?string $reason,
    ) {}
}
