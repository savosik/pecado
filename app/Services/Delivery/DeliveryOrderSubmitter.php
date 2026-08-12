<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\DeliveryShipmentStatus;
use App\Jobs\Delivery\FetchDeliveryTrackingJob;
use App\Models\Delivery\DeliveryShipment;
use App\Models\Delivery\DeliveryShipmentStatusHistory;
use App\Models\ShipmentItem;
use App\Services\Delivery\ApiShip\ApiShipClient;
use Illuminate\Support\Facades\Log;

/**
 * Передача заявки перевозчику (POST /orders) и её отмена.
 *
 * Создание у ApiShip асинхронное: в ответе приходит только `orderId`, а трек-номер
 * появляется позже — вебхуком или через GET /orders/{id}. Поэтому «заявка принята»
 * здесь не равно «груз поехал».
 *
 * Ключ идемпотентности — `clientNumber` = номер отправки. Повторная отправка того же
 * номера у ApiShip создаёт конфликт (409), и это правильное поведение: лучше явная
 * ошибка, чем две заявки на один груз.
 */
class DeliveryOrderSubmitter
{
    public function __construct(
        private readonly ApiShipClient $client,
        private readonly DeliveryAddressResolver $addresses,
    ) {}

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function submit(DeliveryShipment $delivery, ?int $triggeredBy = null): array
    {
        if ($delivery->apiship_order_id) {
            return ['ok' => false, 'error' => 'Заявка уже передана перевозчику.'];
        }

        if (! $delivery->provider_key || ! $delivery->tariff_id) {
            return ['ok' => false, 'error' => 'Не выбран тариф доставки.'];
        }

        if ($delivery->places()->count() === 0) {
            return ['ok' => false, 'error' => 'Не заданы грузовые места.'];
        }

        $delivery->forceFill([
            'status' => DeliveryShipmentStatus::SUBMITTING,
            'last_error' => null,
        ])->save();

        $result = $this->client->createOrder($this->buildPayload($delivery), $delivery->id, $triggeredBy);

        if (! $result->ok) {
            $delivery->forceFill([
                'status' => DeliveryShipmentStatus::FAILED,
                'last_error' => $result->error,
            ])->save();

            Log::warning('ApiShip: заявка не создана', [
                'delivery_shipment_id' => $delivery->id,
                'number' => $delivery->number,
                'error' => $result->error,
            ]);

            return ['ok' => false, 'error' => $result->error];
        }

        $orderId = $result->data()['orderId'] ?? null;

        if ($orderId === null) {
            $delivery->forceFill([
                'status' => DeliveryShipmentStatus::FAILED,
                'last_error' => 'ApiShip принял запрос, но не вернул идентификатор заявки',
            ])->save();

            return ['ok' => false, 'error' => 'ApiShip принял запрос, но не вернул идентификатор заявки.'];
        }

        $delivery->forceFill([
            'apiship_order_id' => (string) $orderId,
            'status' => DeliveryShipmentStatus::SUBMITTED,
            'submitted_by' => $triggeredBy,
            'submitted_at' => now(),
        ])->save();

        // Трек-номера в ответе нет — создание асинхронное. Обычно его приносит
        // вебхук; job страхует на случай, если подписка не заведена или потерялась.
        FetchDeliveryTrackingJob::dispatch($delivery->id)->delay(now()->addMinutes(5));

        return ['ok' => true, 'error' => null];
    }

    /**
     * Отменить заявку у перевозчика.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function cancel(DeliveryShipment $delivery, ?int $triggeredBy = null): array
    {
        if (! $delivery->apiship_order_id) {
            // Заявки у перевозчика нет — отменяем локально, ходить в API незачем.
            $delivery->forceFill(['status' => DeliveryShipmentStatus::CANCELLED])->save();

            return ['ok' => true, 'error' => null];
        }

        $result = $this->client->cancelOrder($delivery->apiship_order_id, $delivery->id, $triggeredBy);

        if (! $result->ok) {
            $delivery->forceFill(['last_error' => $result->error])->save();

            return ['ok' => false, 'error' => $result->error];
        }

        $delivery->forceFill([
            'status' => DeliveryShipmentStatus::CANCELLED,
            'last_error' => null,
        ])->save();

        $delivery->statusHistories()->create([
            'from_status_key' => $delivery->apiship_status_key,
            'to_status_key' => 'deliveryCanceled',
            'status_name' => 'Отменено сотрудником склада',
            'source' => DeliveryShipmentStatusHistory::SOURCE_MANUAL,
            'occurred_at' => now(),
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Тело заявки в формате ApiShip.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(DeliveryShipment $delivery): array
    {
        $delivery->loadMissing(['places', 'shipments.items']);

        $recipient = (array) ($delivery->recipient ?? []);
        $sender = $this->addresses->sender();
        $first = $delivery->places->first();

        $order = array_filter([
            'clientNumber' => $delivery->number,
            'providerKey' => $delivery->provider_key,
            'tariffId' => (int) $delivery->tariff_id,
            'pickupType' => (int) $delivery->pickup_type,
            'deliveryType' => (int) $delivery->delivery_type,
            'pickupDate' => $delivery->pickup_date?->format('Y-m-d'),
            'weight' => $delivery->effective_weight,
            'length' => $first?->length,
            'width' => $first?->width,
            'height' => $first?->height,
            'description' => $delivery->comment ?: 'Отправка '.$delivery->number,
            // Пункт выдачи обязателен только при доставке до ПВЗ.
            'pointOutId' => $delivery->isDoorDelivery() ? null : $delivery->point_id,
        ], static fn ($value): bool => $value !== null && $value !== '');

        return [
            'order' => $order,
            // Весь блок — про деньги, которые перевозчик берёт С ПОЛУЧАТЕЛЯ, а не про
            // наши расходы. Наложенного платежа у нас нет: клиент рассчитывается
            // по документам 1С, доставку мы оплачиваем сами по счёту перевозчика.
            //
            // Поэтому `deliveryCost` здесь ноль, а не наш тариф. С тарифом в этом поле
            // DPD отвечает «сумма к получению товарных позиций не совпадает с суммой
            // к получению отправления»: у позиций к получению ноль, а по отправлению —
            // стоимость доставки. Свою стоимость храним в `delivery_shipments`.
            'cost' => [
                'assessedCost' => (float) $delivery->assessed_cost,
                'codCost' => 0,
                'deliveryCost' => 0,
            ],
            'sender' => $sender,
            'recipient' => $recipient,
            // Возврат едет туда же, откуда уехал груз.
            'returnAddress' => $sender,
            'places' => $this->buildPlaces($delivery),
        ];
    }

    /**
     * Грузовые места с товарным составом.
     *
     * Весь товар кладём в первое место: разложить позиции по коробкам физически
     * может только кладовщик, а такого учёта у нас нет. Перевозчику нужен состав
     * для описи, и опись груза в целом его устраивает.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPlaces(DeliveryShipment $delivery): array
    {
        $places = [];

        foreach ($delivery->places as $place) {
            $places[] = array_filter([
                'placeNumber' => (string) $place->number,
                'weight' => (int) $place->weight,
                'length' => $place->length,
                'width' => $place->width,
                'height' => $place->height,
                'items' => $place->number === 1 ? $this->buildItems($delivery) : null,
            ], static fn ($value): bool => $value !== null);
        }

        return $places;
    }

    /**
     * Товарный состав для описи.
     *
     * Обе денежные величины позиции — **за единицу**: перевозчик умножает их на
     * `quantity` (проверено на боевом API — заявка с `cost` 50 × 2 сходится с
     * `codCost` 100, а не 50).
     *
     * `assessedCost` — объявленная ценность, по ней считается страховка и она же
     * видна в описи. Берём `total` строки, то есть сумму со всеми скидками, делённую
     * на количество: сумма таких строк сходится с `assessed_cost` отправки копейка
     * в копейку, а `price` — цена до скидок, и опись бы завысила.
     *
     * `cost` — **сумма к получению с покупателя**, то есть доля наложенного платежа.
     * У нас его нет, клиент рассчитывается по документам 1С, поэтому ноль: перевозчик
     * сверяет сумму `cost` по позициям с `codCost + deliveryCost` заявки.
     *
     * @return list<array<string, mixed>>
     */
    private function buildItems(DeliveryShipment $delivery): array
    {
        $items = [];

        foreach ($delivery->shipments as $shipment) {
            foreach ($shipment->items as $item) {
                /** @var ShipmentItem $item */
                $quantity = max(1, (int) $item->quantity);
                $lineTotal = (float) ($item->total ?? 0);

                $items[] = [
                    'description' => (string) ($item->product_name_snapshot ?: 'Товар'),
                    'quantity' => $quantity,
                    'assessedCost' => round(($lineTotal ?: (float) $item->price * $quantity) / $quantity, 2),
                    'cost' => 0.0,
                ];
            }
        }

        return $items;
    }
}
