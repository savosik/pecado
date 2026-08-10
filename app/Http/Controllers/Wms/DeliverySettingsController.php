<?php

namespace App\Http\Controllers\Wms;

use App\Services\Delivery\ApiShip\ApiShipClient;
use App\Services\Delivery\ApiShipSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройки интеграции с ApiShip — ведёт начальник склада.
 *
 * До этого токен и адрес отправителя жили только в `.env`, то есть менялись
 * деплоем. Для склада это нерабочая схема: договор с перевозчиком заключают
 * и перевыпускают без участия разработчика.
 *
 * Значения из формы перекрывают `.env` (см. ApiShipSettings), а секреты
 * шифруются и наружу не отдаются — только признак «задано».
 */
class DeliverySettingsController extends WmsController
{
    public function __construct(
        private readonly ApiShipSettings $settings,
        private readonly ApiShipClient $client,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Wms/Pages/Deliveries/Settings', [
            'settings' => $this->settings->forForm(),
            'missing' => $this->settings->missing(),
            'integrationEnabled' => $this->client->enabled(),
            'webhookUrl' => $this->webhookUrl(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
            'base_url' => ['required', 'string', 'max:255', 'url'],
            'token' => ['nullable', 'string', 'max:255'],
            'clear_token' => ['boolean'],
            'login' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'clear_password' => ['boolean'],
            'timeout' => ['required', 'integer', 'min:5', 'max:120'],

            'webhook_enabled' => ['boolean'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'clear_webhook_secret' => ['boolean'],

            'sender_company_name' => ['nullable', 'string', 'max:255'],
            'sender_contact_name' => ['nullable', 'string', 'max:255'],
            'sender_phone' => ['nullable', 'string', 'max:32'],
            'sender_email' => ['nullable', 'email', 'max:255'],
            'sender_country_code' => ['nullable', 'string', 'size:2'],
            'sender_index' => ['nullable', 'string', 'max:10'],
            'sender_region' => ['nullable', 'string', 'max:150'],
            'sender_city' => ['nullable', 'string', 'max:150'],
            'sender_street' => ['nullable', 'string', 'max:255'],
            'sender_house' => ['nullable', 'string', 'max:50'],

            'default_item_weight_grams' => ['required', 'integer', 'min:1', 'max:100000'],
            'default_place_length' => ['required', 'integer', 'min:1', 'max:500'],
            'default_place_width' => ['required', 'integer', 'min:1', 'max:500'],
            'default_place_height' => ['required', 'integer', 'min:1', 'max:500'],
        ], [
            'base_url.required' => 'Укажите адрес API ApiShip.',
            'base_url.url' => 'Адрес API должен быть корректной ссылкой.',
            'timeout.required' => 'Укажите таймаут запроса.',
            'timeout.min' => 'Таймаут меньше 5 секунд не даст перевозчику ответить.',
            'timeout.max' => 'Таймаут больше 120 секунд повесит интерфейс склада.',
            'webhook_secret.min' => 'Секрет вебхука должен быть не короче 16 символов — он передаётся прямо в адресе.',
            'sender_email.email' => 'Некорректный email отправителя.',
            'sender_country_code.size' => 'Код страны состоит из двух букв (например RU).',
            'default_item_weight_grams.required' => 'Укажите вес позиции по умолчанию.',
            'default_item_weight_grams.min' => 'Ноль слать нельзя: перевозчик посчитает тариф по объёмному весу.',
            'default_place_length.required' => 'Укажите длину типовой коробки.',
            'default_place_width.required' => 'Укажите ширину типовой коробки.',
            'default_place_height.required' => 'Укажите высоту типовой коробки.',
        ]);

        $this->settings->save($validated);

        return back()->with('success', 'Настройки доставки сохранены.');
    }

    /**
     * Проверка связи: дёргаем безобидный справочник и показываем, что ответил ApiShip.
     */
    public function test(): RedirectResponse
    {
        if (! $this->client->enabled()) {
            return back()->with('error', 'Интеграция выключена или не заданы доступы.');
        }

        $result = $this->client->listWebhooks();

        if (! $result->ok) {
            return back()->with('error', 'ApiShip не ответил: '.$result->error);
        }

        // Ответ 200 подтверждает только доступ. Тарифы считаются лишь по подключённым
        // в кабинете ApiShip перевозчикам, и без них расчёт вернёт пустой список.
        $connections = $this->client->getPoints(['limit' => 1, 'offset' => 0, 'filter' => '']);

        return back()->with(
            'success',
            $connections->ok
                ? 'Связь с ApiShip есть, доступы приняты.'
                : 'Авторизация прошла, но справочники недоступны: '.$connections->error,
        );
    }

    /**
     * Адрес вебхука с уже подставленным секретом — его копируют в кабинет ApiShip.
     */
    private function webhookUrl(): ?string
    {
        $secret = $this->settings->string('webhook_secret');

        if ($secret === '') {
            return null;
        }

        return route('api.delivery.apiship.webhook', ['secret' => $secret]);
    }
}
