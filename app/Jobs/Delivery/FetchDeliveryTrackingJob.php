<?php

namespace App\Jobs\Delivery;

use App\Models\Delivery\DeliveryShipment;
use App\Services\Delivery\ApiShip\ApiShipClient;
use App\Services\Delivery\DeliveryStatusSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Добор трек-номера по свежепереданной заявке.
 *
 * Создание заявки у ApiShip асинхронное: в ответе только `orderId`, а номер
 * перевозчика приезжает вебхуком через несколько минут. Если вебхук выключен
 * (или подписка не заведена), трек не появится вовсе — этот job закрывает дыру.
 *
 * Чтение идемпотентно, поэтому здесь ретраи уместны, в отличие от передачи заявки.
 */
class FetchDeliveryTrackingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $deliveryShipmentId) {}

    public function handle(ApiShipClient $client, DeliveryStatusSynchronizer $synchronizer): void
    {
        $delivery = DeliveryShipment::query()->find($this->deliveryShipmentId);

        if ($delivery === null || ! $delivery->apiship_order_id) {
            return;
        }

        if ($delivery->provider_number) {
            return;
        }

        $result = $client->getOrder($delivery->apiship_order_id, $delivery->id);

        if (! $result->ok) {
            // Пусть отработают ретраи: перевозчик мог ещё не создать отправление.
            $this->release(300);

            return;
        }

        $data = $result->data();
        $orderInfo = is_array($data['order'] ?? null) ? $data['order'] : $data;
        $status = is_array($data['status'] ?? null) ? $data['status'] : [];

        $synchronizer->apply($delivery, $orderInfo, $status, $synchronizer->sourcePoll());
    }
}
