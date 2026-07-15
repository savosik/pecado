<?php

namespace App\Services\Erp\Exceptions;

use RuntimeException;

/**
 * v15.4: Сообщение из 1С валидно по схеме, но обработать его невозможно,
 * и повторная попытка не поможет.
 *
 * В отличие от обычного исключения, не приводит к возврату сообщения в очередь:
 * ErpIncomingJob помечает такое сообщение как `failed` с текстом причины и
 * удаляет из очереди. Ошибка видна в админке («Шина ERP» → «Ошибки обработки»).
 *
 * Пример: order.updated по заказу, которого нет на сайте, и данных в payload
 * не хватает, чтобы восстановить заказ (см. rules/orders.md, «Самовосстановление»).
 */
class ErpUnprocessableMessageException extends RuntimeException {}
