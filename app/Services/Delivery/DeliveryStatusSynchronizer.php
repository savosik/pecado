<?php

namespace App\Services\Delivery;

use App\Enums\Delivery\ApiShipStatus;
use App\Models\Delivery\DeliveryShipment;
use App\Models\Delivery\DeliveryShipmentStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Единственная точка приёма статуса от перевозчика.
 *
 * Общая и для вебхука ORDER_STATUS, и для периодической сверки — иначе две ветки
 * неизбежно разъедутся в трактовке одних и тех же данных. Дубли гарантированы
 * по построению (вебхук и сверка видят одно событие), поэтому запись в журнал
 * идёт только при фактической смене ключа статуса.
 */
class DeliveryStatusSynchronizer
{
    /**
     * Применить статус к отправке.
     *
     * @param  array<string, mixed>  $orderInfo  блок orderInfo из вебхука или ответа API
     * @param  array<string, mixed>  $status  блок status
     * @return bool изменилось ли что-нибудь
     */
    public function apply(DeliveryShipment $delivery, array $orderInfo, array $status, string $source): bool
    {
        $key = $this->string($status, 'key');
        $previousKey = $delivery->apiship_status_key;
        $occurredAt = $this->parseDate($status['created'] ?? null);
        $changed = false;

        // Идентификаторы приезжают вместе со статусом и могут появиться не сразу:
        // создание заявки асинхронное, трек-номер выдаётся уже после неё.
        $identifiers = array_filter([
            'provider_number' => $this->string($orderInfo, 'providerNumber'),
            'barcode' => $this->string($orderInfo, 'barcode'),
            'tracking_url' => $this->string($orderInfo, 'trackingUrl'),
            'apiship_order_id' => $this->string($orderInfo, 'orderId'),
        ], static fn ($value): bool => $value !== null);

        foreach ($identifiers as $field => $value) {
            if ((string) $delivery->{$field} !== $value) {
                $delivery->{$field} = $value;
                $changed = true;
            }
        }

        // Статуса в ответе может не быть вовсе: FetchDeliveryTrackingJob читает
        // карточку заявки ради трек-номера, и там приходят только идентификаторы.
        if ($key !== null && $previousKey !== $key) {
            $enum = ApiShipStatus::tryFrom($key);

            $delivery->fill([
                'apiship_status_key' => $key,
                'apiship_status_name' => $this->string($status, 'name') ?? $enum?->label(),
                'apiship_status_at' => $occurredAt,
                // Незнакомый ключ не должен ломать приём: сохраняем как есть,
                // а внутренний статус оставляем прежним — пусть решает человек.
                'status' => $enum?->toShipmentStatus() ?? $delivery->status,
            ]);

            $delivery->statusHistories()->create([
                'from_status_key' => $previousKey,
                'to_status_key' => $key,
                'status_name' => $this->string($status, 'name') ?? $enum?->label(),
                'provider_code' => $this->string($status, 'providerCode'),
                'source' => $source,
                'occurred_at' => $occurredAt,
            ]);

            if ($enum === null) {
                Log::info('ApiShip: неизвестный ключ статуса', [
                    'delivery_shipment_id' => $delivery->id,
                    'status_key' => $key,
                ]);
            }

            $changed = true;
        }

        if ($changed) {
            $delivery->save();
        }

        return $changed;
    }

    /**
     * Найти отправку по данным перевозчика.
     *
     * `clientNumber` — наш номер и основной ключ; `orderId` подстраховывает случай,
     * когда перевозчик его не вернул.
     *
     * @param  array<string, mixed>  $orderInfo
     */
    public function resolve(array $orderInfo): ?DeliveryShipment
    {
        $clientNumber = $this->string($orderInfo, 'clientNumber');
        $orderId = $this->string($orderInfo, 'orderId');

        return DeliveryShipment::query()
            ->when($clientNumber !== null, fn ($query) => $query->where('number', $clientNumber))
            ->when($clientNumber === null && $orderId !== null, fn ($query) => $query->where('apiship_order_id', $orderId))
            ->first();
    }

    /**
     * Источник для журнала: вебхук или сверка.
     */
    public function sourceWebhook(): string
    {
        return DeliveryShipmentStatusHistory::SOURCE_WEBHOOK;
    }

    public function sourcePoll(): string
    {
        return DeliveryShipmentStatusHistory::SOURCE_POLL;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function parseDate(mixed $value): Carbon
    {
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // Перевозчик прислал дату в неизвестном формате — не повод терять событие.
            }
        }

        return Carbon::now();
    }
}
