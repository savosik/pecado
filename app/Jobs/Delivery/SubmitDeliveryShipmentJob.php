<?php

namespace App\Jobs\Delivery;

use App\Models\Delivery\DeliveryShipment;
use App\Services\Delivery\DeliveryOrderSubmitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Фоновая передача заявки перевозчику.
 *
 * `tries = 1` намеренно, ровно как у SendPreorderToSupplierJob: POST /orders
 * неидемпотентен, и автоматический повтор после таймаута завёл бы у перевозчика
 * вторую заявку на тот же груз. Неудача видна в карточке отправки (статус
 * «Ошибка передачи» плюс текст ответа), решение о повторе принимает кладовщик.
 *
 * Сейчас заявка уходит синхронно из контроллера — кладовщик должен увидеть
 * результат сразу. Job нужен для массовой передачи нескольких отправок разом,
 * когда она появится.
 */
class SubmitDeliveryShipmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $deliveryShipmentId,
        public ?int $triggeredBy = null,
    ) {
        $this->queue = 'default';
    }

    public function handle(DeliveryOrderSubmitter $submitter): void
    {
        $delivery = DeliveryShipment::query()->find($this->deliveryShipmentId);

        if ($delivery === null) {
            Log::info('ApiShip: отправка удалена до передачи заявки', ['id' => $this->deliveryShipmentId]);

            return;
        }

        if ($delivery->apiship_order_id) {
            Log::info('ApiShip: заявка уже передана, повтор пропущен', ['id' => $delivery->id]);

            return;
        }

        $submitter->submit($delivery, $this->triggeredBy);
    }
}
