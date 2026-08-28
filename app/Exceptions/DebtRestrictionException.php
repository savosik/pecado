<?php

namespace App\Exceptions;

use App\Enums\DebtLevel;
use Exception;

/**
 * Чекаут отклонён лестницей долга (карточка debt-04).
 *
 * Несёт всё, что нужно объяснить клиенту: ступень, сумму просрочки, контрагента
 * и что именно закрыто. Контроллер превращает это в ошибку формы + flash.
 */
class DebtRestrictionException extends Exception
{
    public function __construct(
        public readonly DebtLevel $level,
        public readonly float $overdueAmount,
        public readonly ?string $companyName,
        public readonly bool $blocksAllOrders,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'level' => $this->level->value,
            'level_label' => $this->level->label(),
            'overdue_amount' => $this->overdueAmount,
            'company_name' => $this->companyName,
            'blocks_all_orders' => $this->blocksAllOrders,
            'message' => $this->getMessage(),
        ];
    }
}
