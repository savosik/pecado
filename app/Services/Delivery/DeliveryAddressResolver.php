<?php

namespace App\Services\Delivery;

use App\Services\DaData\DaDataClient;
use App\Services\DaData\DaDataException;
use Illuminate\Support\Facades\Log;

/**
 * Приводит адрес получателя к формату ApiShip.
 *
 * Источников три и все они дают разное качество данных:
 *  - адрес из заказа (`orders.delivery_address`) — свободный текст;
 *  - адрес из справочника клиента (`delivery_addresses`) — иногда с разобранным
 *    `address_data` от DaData, иногда тоже строкой;
 *  - ручной ввод в мастере — подсказка DaData целиком.
 *
 * ApiShip требует адрес разобранным на регион/город/улицу/дом, поэтому свободный
 * текст прогоняется через тот же DaData, что уже используется в проекте. Если
 * разобрать не удалось, отдаём `addressString` и помечаем адрес неточным —
 * мастер в этом случае требует ручного подтверждения, а не отправляет заявку вслепую.
 */
class DeliveryAddressResolver
{
    public function __construct(
        private readonly DaDataClient $daData,
        private readonly ApiShipSettings $settings,
    ) {}

    /**
     * Разобрать свободную строку адреса.
     *
     * @return array{address: array<string, mixed>, resolved: bool}
     */
    public function fromString(string $address): array
    {
        $address = trim($address);

        if ($address === '') {
            return ['address' => [], 'resolved' => false];
        }

        try {
            $suggestions = $this->daData->suggestAddress($address, 1);
        } catch (DaDataException $e) {
            // Подсказки — вспомогательный сервис: его недоступность не должна
            // блокировать склад, кладовщик доразберёт адрес руками.
            Log::warning('ApiShip: DaData не разобрала адрес доставки', [
                'address' => $address,
                'exception' => $e->getMessage(),
            ]);

            return ['address' => ['addressString' => $address], 'resolved' => false];
        }

        $suggestion = $suggestions[0] ?? null;

        if (! is_array($suggestion)) {
            return ['address' => ['addressString' => $address], 'resolved' => false];
        }

        return $this->fromSuggestion($suggestion);
    }

    /**
     * Разобрать подсказку DaData (её же отдаёт компонент AddressSuggest на фронте).
     *
     * @param  array<string, mixed>  $suggestion
     * @return array{address: array<string, mixed>, resolved: bool}
     */
    public function fromSuggestion(array $suggestion): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($suggestion['data'] ?? null) ? $suggestion['data'] : [];

        $value = (string) ($suggestion['unrestricted_value'] ?? $suggestion['value'] ?? '');

        // Город может приехать как city (город), settlement (посёлок) или
        // area (район) — у мелких населённых пунктов заполнен именно settlement.
        $city = $this->str($data, 'city')
            ?: $this->str($data, 'settlement')
            ?: $this->str($data, 'area');

        $address = array_filter([
            'countryCode' => $this->str($data, 'country_iso_code') ?: 'RU',
            'index' => $this->str($data, 'postal_code'),
            'region' => $this->str($data, 'region_with_type') ?: $this->str($data, 'region'),
            'area' => $this->str($data, 'area_with_type'),
            'city' => $city,
            'street' => $this->str($data, 'street_with_type') ?: $this->str($data, 'street'),
            'house' => $this->str($data, 'house'),
            'block' => $this->str($data, 'block'),
            'office' => $this->str($data, 'flat'),
            'addressString' => $value,
            'lat' => $this->float($data, 'geo_lat'),
            'lng' => $this->float($data, 'geo_lon'),
        ], static fn ($item): bool => $item !== null && $item !== '');

        // Минимум для расчёта тарифа — город. Без дома заявку тоже не примут,
        // но до дома можно доуточнить в форме, а без города даже считать нечего.
        $resolved = isset($address['city']) && isset($address['house']);

        return ['address' => $address, 'resolved' => $resolved];
    }

    /**
     * Разобрать `delivery_addresses.address_data` — там лежит сохранённый ответ DaData.
     *
     * @param  array<string, mixed>|null  $addressData
     * @return array{address: array<string, mixed>, resolved: bool}
     */
    public function fromStoredAddress(?array $addressData, string $fallbackString): array
    {
        if ($addressData === null || $addressData === []) {
            return $this->fromString($fallbackString);
        }

        // В справочнике мог сохраниться как весь suggestion, так и только его data.
        $suggestion = isset($addressData['data']) && is_array($addressData['data'])
            ? $addressData
            : ['value' => $fallbackString, 'data' => $addressData];

        $result = $this->fromSuggestion($suggestion);

        return $result['resolved'] ? $result : $this->fromString($fallbackString);
    }

    /**
     * Отправитель — наш склад, из конфига.
     *
     * @return array<string, mixed>
     */
    public function sender(): array
    {
        return array_filter([
            'companyName' => $this->settings->string('sender_company_name'),
            'contactName' => $this->settings->string('sender_contact_name'),
            'phone' => $this->settings->string('sender_phone'),
            'email' => $this->settings->string('sender_email'),
            'countryCode' => $this->settings->string('sender_country_code') ?: 'RU',
            'index' => $this->settings->string('sender_index'),
            'region' => $this->settings->string('sender_region'),
            'city' => $this->settings->string('sender_city'),
            'street' => $this->settings->string('sender_street'),
            'house' => $this->settings->string('sender_house'),
        ], static fn (string $item): bool => $item !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
