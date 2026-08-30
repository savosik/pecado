<?php

namespace App\Listeners\Payroll;

use App\Events\PartnerSettlementsChanged;
use App\Jobs\Payroll\ProjectInvoiceSettlements;

/**
 * Новые движения регистра по партнёру → пересобрать его накладные для зарплаты.
 *
 * Сам слушатель лёгкий: только ставит джоб. Проекция и пересчёт черновиков
 * идут в очереди, чтобы не задерживать обработку сообщения 1С.
 */
class ReactToSettlementChanges
{
    public function handle(PartnerSettlementsChanged $event): void
    {
        if ($event->userIds === []) {
            return;
        }

        ProjectInvoiceSettlements::dispatch($event->userIds, $event->source);
    }
}
