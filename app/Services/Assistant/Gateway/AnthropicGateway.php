<?php

namespace App\Services\Assistant\Gateway;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\RequestOptions;
use GuzzleHttp\Client as GuzzleClient;
use Throwable;

/**
 * Шлюз к Anthropic через официальный PHP SDK.
 *
 * Прод ходит к API только через контейнер outbound-proxy (напрямую — 403 по
 * региону), поэтому транспорт — Guzzle с прокси из конфига; тот же прокси,
 * что у OpenRouter, если свой не задан. Ошибки SDK переводятся в
 * {@see GatewayException} с видом, по которому воркер решает, что делать.
 */
final class AnthropicGateway implements AssistantGateway
{
    private ?Client $client = null;

    public function stream(array $request): iterable
    {
        try {
            $stream = $this->client()->beta->messages->createStream(...$request);

            foreach ($stream as $event) {
                yield self::wire($event);
            }
        } catch (Throwable $e) {
            throw self::translate($e);
        }
    }

    public function create(array $request): array
    {
        try {
            return self::wire($this->client()->beta->messages->create(...$request));
        } catch (Throwable $e) {
            throw self::translate($e);
        }
    }

    public function uploadFile(string $path, string $filename, string $mime): string
    {
        try {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new GatewayException(GatewayException::INVALID, "Не удалось открыть файл {$filename}.");
            }

            $file = $this->client()->files->upload(file: $handle);

            return (string) $file->id;
        } catch (GatewayException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw self::translate($e);
        }
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $apiKey = (string) config('assistant.api_key');

        if ($apiKey === '') {
            throw new GatewayException(GatewayException::AUTH, 'Ключ Anthropic не задан (ANTHROPIC_API_KEY).');
        }

        $guzzle = new GuzzleClient(array_filter([
            'timeout' => (int) config('assistant.timeout', 300),
            'connect_timeout' => 15,
            'proxy' => config('assistant.proxy'),
        ]));

        return $this->client = new Client(
            apiKey: $apiKey,
            baseUrl: config('assistant.base_url') ?: null,
            requestOptions: RequestOptions::with(
                timeout: (float) config('assistant.timeout', 300),
                maxRetries: 1,
                transporter: $guzzle,
            ),
        );
    }

    /**
     * SDK-объект → wire-формат API (snake_case), как хранит chat_messages.
     *
     * @return array<string, mixed>
     */
    private static function wire(mixed $value): array
    {
        $decoded = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    public static function translate(Throwable $e): GatewayException
    {
        if ($e instanceof GatewayException) {
            return $e;
        }

        if ($e instanceof APITimeoutException) {
            return new GatewayException(GatewayException::TIMEOUT, 'Anthropic не ответил вовремя.', null, $e);
        }

        if ($e instanceof APIConnectionException) {
            return new GatewayException(GatewayException::CONNECTION, 'Нет связи с Anthropic: '.$e->getMessage(), null, $e);
        }

        if ($e instanceof APIStatusException) {
            $status = (int) ($e->status ?? 0);
            $message = self::apiMessage($e);
            $lower = mb_strtolower($message);

            $kind = match (true) {
                $status === 402,
                str_contains($lower, 'credit balance'),
                str_contains($lower, 'spend limit'),
                str_contains($lower, 'billing') => GatewayException::BILLING,
                $status === 401 => GatewayException::AUTH,
                $status === 403 => GatewayException::FORBIDDEN,
                $status === 429 => GatewayException::RATE_LIMIT,
                $status === 529, $status >= 500 => GatewayException::OVERLOADED,
                $status === 400, $status === 404, $status === 422 => GatewayException::INVALID,
                default => GatewayException::UNKNOWN,
            };

            return new GatewayException($kind, $message, $status ?: null, $e);
        }

        if ($e instanceof AnthropicException) {
            return new GatewayException(GatewayException::UNKNOWN, $e->getMessage(), null, $e);
        }

        return new GatewayException(GatewayException::UNKNOWN, $e->getMessage(), null, $e);
    }

    private static function apiMessage(APIStatusException $e): string
    {
        $body = $e->body;

        if (is_array($body)) {
            $nested = $body['error']['message'] ?? $body['message'] ?? null;

            if (is_string($nested) && $nested !== '') {
                return $nested;
            }
        }

        return $e->getMessage() !== '' ? $e->getMessage() : 'Ошибка API Anthropic';
    }
}
