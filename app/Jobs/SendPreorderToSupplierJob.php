<?php

namespace App\Jobs;

use App\Enums\OrderType;
use App\Models\Order;
use App\Services\Supplier\SupplierPreorderSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Отправка предзаказа поставщику (Customer API sex-opt.ru → «поставьте в резерв»).
 *
 * Автоматически ставится в очередь листенером App\Listeners\SendPreorderToSupplier
 * при создании предзаказа; вручную — кнопкой «Отправить повторно» в админке.
 *
 * `tries = 1` намеренно: у метода send_order нет ключа идемпотентности, и
 * автоматический ретрай после таймаута мог бы создать у поставщика второй заказ.
 * Неудачная попытка видна в админке (журнал «Предзаказы поставщику»), решение о
 * повторе принимает менеджер.
 */
class SendPreorderToSupplierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $orderId,
        public ?int $triggeredBy = null,
        public bool $force = false,
    ) {
        $this->queue = 'default';
    }

    public function handle(SupplierPreorderSender $sender): void
    {
        if (! config('services.sex_opt.preorder.enabled')) {
            Log::info('Отправка предзаказов поставщику выключена (SUPPLIER_PREORDER_ENABLED)', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        $order = Order::with('items.product')->find($this->orderId);

        if (! $order) {
            Log::warning('Предзаказ не найден, отправка поставщику отменена', ['order_id' => $this->orderId]);

            return;
        }

        $type = $order->type->value;

        if ($type !== OrderType::PREORDER->value) {
            Log::warning('Заказ не является предзаказом, отправка поставщику отменена', [
                'order_id' => $order->id,
                'type' => $type,
            ]);

            return;
        }

        // Защита от дублей: заказ, уже созданный у поставщика, повторно не шлём,
        // пока менеджер явно не потребует этого (force).
        if (! $this->force && $sender->alreadySent($order)) {
            Log::info('Предзаказ уже отправлен поставщику, повтор пропущен', ['order_id' => $order->id]);

            return;
        }

        $sender->send($order, $this->triggeredBy);
    }
}
