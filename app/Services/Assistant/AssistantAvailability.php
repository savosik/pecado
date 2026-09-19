<?php

namespace App\Services\Assistant;

use App\Events\AssistantAvailabilityChanged;
use App\Services\Assistant\Gateway\AssistantGateway;
use App\Services\Assistant\Gateway\GatewayException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Доступен ли помощник всем клиентам прямо сейчас.
 *
 * Кончился баланс, закрыт доступ, нет ключа, лёг прокси — помощник исчезает
 * целиком: ни иконки, ни реплик, ни страницы. Недоступный сервис не
 * рекламируют (решение заказчика 19.09.2026). Пока флаг снят, планировщик
 * делает пробный запрос минимальной стоимости и возвращает помощника сам.
 *
 * Состояние живёт в кеше, а не в конфиге: его меняет воркер по ошибке API,
 * а читает каждый запрос страницы.
 */
final class AssistantAvailability
{
    public const KEY = 'assistant:availability';

    public const AVAILABLE = 'available';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly AssistantGateway $gateway) {}

    /** Рубильник включён и ключ задан — есть смысл вообще что-то показывать. */
    public function configured(): bool
    {
        return (bool) config('assistant.enabled') && (string) config('assistant.api_key') !== '';
    }

    public function isAvailable(): bool
    {
        return $this->configured() && $this->state()['state'] === self::AVAILABLE;
    }

    /**
     * @return array{state: string, reason: string|null, since: string|null}
     */
    public function state(): array
    {
        $stored = Cache::get(self::KEY);

        if (is_array($stored) && isset($stored['state'])) {
            return [
                'state' => (string) $stored['state'],
                'reason' => isset($stored['reason']) ? (string) $stored['reason'] : null,
                'since' => isset($stored['since']) ? (string) $stored['since'] : null,
            ];
        }

        return ['state' => self::AVAILABLE, 'reason' => null, 'since' => null];
    }

    public function markUnavailable(string $reason): void
    {
        $was = $this->state();

        Cache::forever(self::KEY, [
            'state' => self::UNAVAILABLE,
            'reason' => mb_substr($reason, 0, 500),
            'since' => $was['state'] === self::UNAVAILABLE && $was['since'] ? $was['since'] : now()->toIso8601String(),
        ]);

        if ($was['state'] !== self::UNAVAILABLE) {
            Log::warning('Помощник клиента недоступен', ['reason' => $reason]);
            event(new AssistantAvailabilityChanged(false, $reason));
        }
    }

    public function markAvailable(): void
    {
        $was = $this->state();

        Cache::forever(self::KEY, ['state' => self::AVAILABLE, 'reason' => null, 'since' => now()->toIso8601String()]);

        if ($was['state'] === self::UNAVAILABLE) {
            Log::info('Помощник клиента снова доступен');
            event(new AssistantAvailabilityChanged(true, null));
        }
    }

    /**
     * Ошибка шлюза из воркера: фатальная снимает флаг, временная — нет.
     */
    public function observe(GatewayException $e): void
    {
        if ($e->disablesAssistant()) {
            $this->markUnavailable($e->kind.': '.$e->getMessage());
        }
    }

    /**
     * Пробный запрос минимальной стоимости: успех возвращает помощника,
     * фатальный отказ подтверждает недоступность. Временные ошибки состояние
     * не меняют — следующая проба разберётся.
     *
     * @return array{ok: bool, message: string, usage?: array<string, mixed>, model?: string}
     */
    public function probe(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Помощник выключен или ключ не задан.'];
        }

        try {
            $message = $this->gateway->create([
                'model' => (string) config('assistant.model'),
                'maxTokens' => 8,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);
        } catch (GatewayException $e) {
            $this->observe($e);

            return ['ok' => false, 'message' => "[{$e->kind}] {$e->getMessage()}"];
        }

        $this->markAvailable();

        return [
            'ok' => true,
            'message' => 'Anthropic отвечает.',
            'usage' => is_array($message['usage'] ?? null) ? $message['usage'] : [],
            'model' => (string) ($message['model'] ?? ''),
        ];
    }
}
