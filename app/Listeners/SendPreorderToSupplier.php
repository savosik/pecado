<?php

namespace App\Listeners;

use App\Jobs\SendPreorderToSupplierJob;
use App\Services\Supplier\SupplierPreorderSender;

/**
 * При создании предзаказа ставит в очередь отправку поставщику.
 *
 * Сам HTTP-запрос выполняется в job-е: чекаут не должен ждать чужой API.
 */
class SendPreorderToSupplier
{
    public function __construct(private readonly SupplierPreorderSender $sender) {}

    public function handle(object $event): void
    {
        if (! isset($event->order)) {
            return;
        }

        $order = $event->order;

        // Заказы, приехавшие из 1С, поставщику не отправляем: резерв по ним
        // ставится на стороне 1С, иначе получим двойной заказ у поставщика.
        if ($order->fromErp) {
            return;
        }

        if (! $this->sender->shouldSendAutomatically($order)) {
            return;
        }

        SendPreorderToSupplierJob::dispatch($order->id);
    }
}
