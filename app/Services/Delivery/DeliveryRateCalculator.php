<?php

namespace App\Services\Delivery;

use App\Models\Delivery\DeliveryShipment;
use App\Services\Delivery\ApiShip\ApiShipClient;
use Illuminate\Support\Facades\Cache;

/**
 * Расчёт стоимости доставки: собирает запрос к POST /calculator и раскладывает
 * ответ в плоский список тарифов для таблицы выбора.
 *
 * Ответ ApiShip приходит двумя ветками — `deliveryToDoor` и `deliveryToPoint`, —
 * внутри каждой перевозчики со своими тарифами. Складу нужен один список,
 * отсортированный по цене, с пометкой куда именно едет груз.
 *
 * Каждый вызов калькулятора ApiShip считает транзакцией и тарифицирует, поэтому
 * одинаковые запросы в пределах окна отдаются из кэша.
 */
class DeliveryRateCalculator
{
    public function __construct(
        private readonly ApiShipClient $client,
        private readonly DeliveryAddressResolver $addresses,
        private readonly ApiShipSettings $settings,
    ) {}

    /**
     * Посчитать тарифы для отправки.
     *
     * @return array{ok: bool, error: string|null, tariffs: list<array<string, mixed>>}
     */
    public function forShipment(DeliveryShipment $delivery, ?int $triggeredBy = null): array
    {
        $recipient = (array) ($delivery->recipient ?? []);

        if (($recipient['city'] ?? null) === null) {
            return ['ok' => false, 'error' => 'Не указан город получателя — расчёт невозможен.', 'tariffs' => []];
        }

        $payload = $this->buildPayload($delivery, $recipient);
        $cacheKey = 'apiship:calculator:'.md5(json_encode($payload, JSON_THROW_ON_ERROR));
        $ttl = (int) config('services.apiship.calculator_cache_ttl', 600);

        /** @var array{ok: bool, error: string|null, tariffs: list<array<string, mixed>>}|null $cached */
        $cached = $ttl > 0 ? Cache::get($cacheKey) : null;

        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->client->calculate($payload, $triggeredBy);

        if (! $result->ok) {
            // Неудачу не кэшируем: у перевозчика мог моргнуть сервис,
            // и повторная кнопка «Рассчитать» должна реально пересчитать.
            return ['ok' => false, 'error' => $result->error, 'tariffs' => []];
        }

        $response = [
            'ok' => true,
            'error' => null,
            'tariffs' => $this->flattenTariffs($result->data()),
        ];

        if ($ttl > 0) {
            Cache::put($cacheKey, $response, $ttl);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $recipient
     * @return array<string, mixed>
     */
    private function buildPayload(DeliveryShipment $delivery, array $recipient): array
    {
        $delivery->loadMissing('places');

        $places = $delivery->places
            ->map(static fn ($place): array => array_filter([
                'weight' => (int) $place->weight,
                'length' => $place->length,
                'width' => $place->width,
                'height' => $place->height,
            ], static fn ($value): bool => $value !== null && $value !== 0))
            ->values()
            ->all();

        $first = $delivery->places->first();

        return array_filter([
            'from' => $this->addressBlock($this->addresses->sender()),
            'to' => $this->addressBlock($recipient),
            'weight' => $delivery->effective_weight,
            // Габариты верхнего уровня обязательны даже при переданных places:
            // часть перевозчиков считает тариф именно по ним. Берём первое место,
            // при его отсутствии — типовую коробку из конфига.
            'length' => (int) ($first?->length ?: $this->settings->int('default_place_length', 40)),
            'width' => (int) ($first?->width ?: $this->settings->int('default_place_width', 30)),
            'height' => (int) ($first?->height ?: $this->settings->int('default_place_height', 20)),
            'places' => $places !== [] ? $places : null,
            'assessedCost' => (float) $delivery->assessed_cost,
            'codCost' => 0,
            'pickupDate' => $delivery->pickup_date?->format('Y-m-d'),
            'pickupTypes' => [(int) $delivery->pickup_type],
            'deliveryTypes' => [(int) $delivery->delivery_type],
            'timeout' => 20000,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Адрес в том виде, в каком его ждёт калькулятор: без контактов, только география.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function addressBlock(array $address): array
    {
        return array_filter([
            'countryCode' => $address['countryCode'] ?? 'RU',
            'index' => $address['index'] ?? null,
            'region' => $address['region'] ?? null,
            'city' => $address['city'] ?? null,
            'addressString' => $address['addressString'] ?? null,
            'lat' => $address['lat'] ?? null,
            'lng' => $address['lng'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Схлопнуть deliveryToDoor/deliveryToPoint в один отсортированный список.
     *
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function flattenTariffs(array $data): array
    {
        $branches = [
            DeliveryShipment::DELIVERY_TYPE_DOOR => $data['deliveryToDoor'] ?? [],
            DeliveryShipment::DELIVERY_TYPE_POINT => $data['deliveryToPoint'] ?? [],
        ];

        $rows = [];

        foreach ($branches as $deliveryType => $providers) {
            if (! is_array($providers)) {
                continue;
            }

            foreach ($providers as $provider) {
                if (! is_array($provider)) {
                    continue;
                }

                $providerKey = (string) ($provider['providerKey'] ?? '');
                $tariffs = $provider['tariffs'] ?? [];

                if (! is_array($tariffs)) {
                    continue;
                }

                foreach ($tariffs as $tariff) {
                    if (! is_array($tariff) || ! isset($tariff['tariffId'])) {
                        continue;
                    }

                    $rows[] = [
                        'provider_key' => $providerKey,
                        'tariff_id' => (int) $tariff['tariffId'],
                        'tariff_name' => (string) ($tariff['tariffName'] ?? 'Без названия'),
                        'delivery_type' => $deliveryType,
                        'delivery_cost' => round((float) ($tariff['deliveryCost'] ?? 0), 2),
                        'delivery_cost_original' => isset($tariff['deliveryCostOriginal'])
                            ? round((float) $tariff['deliveryCostOriginal'], 2)
                            : null,
                        'insurance_fee' => round((float) ($tariff['insuranceFee'] ?? 0), 2),
                        'days_min' => isset($tariff['calendarDaysMin']) ? (int) $tariff['calendarDaysMin'] : null,
                        'days_max' => isset($tariff['calendarDaysMax']) ? (int) $tariff['calendarDaysMax'] : null,
                        'point_ids' => is_array($tariff['pointIds'] ?? null) ? array_values($tariff['pointIds']) : [],
                    ];
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['delivery_cost'] <=> $b['delivery_cost']);

        return $rows;
    }
}
