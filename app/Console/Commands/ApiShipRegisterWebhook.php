<?php

namespace App\Console\Commands;

use App\Services\Delivery\ApiShip\ApiShipClient;
use App\Services\Delivery\ApiShipSettings;
use Illuminate\Console\Command;

/**
 * Управление подпиской на вебхук ORDER_STATUS в ApiShip.
 *
 * Разовая операция на среду: подписка живёт в кабинете ApiShip, а не в нашем коде,
 * и после смены домена или секрета её нужно перевыпустить руками. Отсюда команда,
 * а не автоматическая регистрация при старте — молчаливая перерегистрация плодила бы
 * дубли подписок, и каждый статус приходил бы по несколько раз.
 */
class ApiShipRegisterWebhook extends Command
{
    protected $signature = 'apiship:register-webhook
                            {--list : Показать текущие подписки}
                            {--delete= : Удалить подписку по её uuid}';

    protected $description = 'Зарегистрировать (или посмотреть) подписку на вебхук ORDER_STATUS в ApiShip';

    public function handle(ApiShipClient $client, ApiShipSettings $settings): int
    {
        if (! $client->enabled()) {
            $this->error('Интеграция с ApiShip выключена: задайте APISHIP_ENABLED, APISHIP_LOGIN и APISHIP_PASSWORD.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listWebhooks($client);
        }

        if ($uuid = $this->option('delete')) {
            $result = $client->deleteWebhook((string) $uuid);

            if (! $result->ok) {
                $this->error('Удалить подписку не удалось: '.$result->error);

                return self::FAILURE;
            }

            $this->info('Подписка удалена.');

            return self::SUCCESS;
        }

        // Секрет ведёт начальник склада на /wms/delivery-settings, поэтому берём его
        // через настройки: значение из базы перекрывает .env.
        $secret = $settings->string('webhook_secret');

        if ($secret === '') {
            $this->error('Секрет вебхука не задан — укажите его на /wms/delivery-settings, иначе эндпоинт не принимает запросы.');

            return self::FAILURE;
        }

        if (! $settings->bool('webhook_enabled')) {
            $this->warn('Вебхук выключен в настройках: подписка создастся, но эндпоинт будет отвечать 503.');
        }

        $url = route('api.delivery.apiship.webhook', ['secret' => $secret]);

        if (! str_starts_with($url, 'https://')) {
            // Локальный домен ApiShip не резолвит, и подписка тихо повиснет мёртвой.
            $this->warn("Адрес вебхука не по HTTPS: {$url}");
            $this->warn('На dev/prod APP_URL должен быть публичным — иначе ApiShip не достучится.');
        }

        $result = $client->subscribeWebhook($url);

        if (! $result->ok) {
            $this->error('Подписаться не удалось: '.$result->error);

            return self::FAILURE;
        }

        $this->info('Подписка на ORDER_STATUS создана.');
        $this->line('URL: '.preg_replace('/[^\/]+$/', '***', $url));

        return self::SUCCESS;
    }

    private function listWebhooks(ApiShipClient $client): int
    {
        $result = $client->listWebhooks();

        if (! $result->ok) {
            $this->error('Получить список подписок не удалось: '.$result->error);

            return self::FAILURE;
        }

        $rows = $result->data()['rows'] ?? $result->data();

        if (! is_array($rows) || $rows === []) {
            $this->warn('Подписок нет — статусы будут приходить только периодической сверкой.');

            return self::SUCCESS;
        }

        $this->table(
            ['uuid', 'Событие', 'URL'],
            collect($rows)
                ->map(static fn ($row): array => [
                    $row['uuid'] ?? $row['id'] ?? '—',
                    $row['type'] ?? $row['eventType'] ?? '—',
                    // Секрет — последний сегмент пути, в консоль его выводить незачем.
                    preg_replace('/[^\/]+$/', '***', (string) ($row['url'] ?? '')),
                ])
                ->all(),
        );

        return self::SUCCESS;
    }
}
