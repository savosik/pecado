<?php

namespace App\Events;

use App\Enums\ReturnStatus;
use App\Models\ProductReturn;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Статус заявки на возврат изменился (как правило, в результате обработки сообщения
 * `return.updated` из 1С). Используется для уведомления клиента «было → стало».
 *
 * Диспатчится явно из `App\Services\Erp\Handlers\HandleReturnUpdated`, чтобы не
 * стрелять при будущих ручных правках возврата в админке — там event не нужен.
 */
class ReturnStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProductReturn $productReturn,
        public ?ReturnStatus $previousStatus = null,
    ) {}
}
