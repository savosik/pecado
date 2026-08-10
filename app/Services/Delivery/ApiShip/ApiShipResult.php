<?php

namespace App\Services\Delivery\ApiShip;

use Illuminate\Http\Client\Response;

/**
 * Результат одного вызова ApiShip.
 *
 * Исключений клиент не бросает: неуспешный вызов перевозчика — штатная ветка,
 * которую разбирает бизнес-логика и показывает кладовщику. Отсюда объект-результат
 * с уже разобранным текстом ошибки на русском.
 */
final class ApiShipResult
{
    /**
     * @param  array<mixed>|null  $json
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly ?array $json,
        public readonly ?string $raw,
        public readonly ?string $error,
        public readonly int $durationMs,
    ) {}

    /**
     * Интеграция выключена мастер-флагом — запрос даже не отправлялся.
     */
    public static function disabled(): self
    {
        return new self(false, null, null, null, 'Интеграция с ApiShip выключена (APISHIP_ENABLED=false)', 0);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, null, null, $error, 0);
    }

    public static function transportError(string $message, int $durationMs): self
    {
        return new self(false, null, null, null, $message, $durationMs);
    }

    public static function fromResponse(Response $response, int $durationMs): self
    {
        $raw = $response->body();
        $json = json_decode($raw, true);

        if (! is_array($json)) {
            return new self(
                false,
                $response->status(),
                null,
                mb_substr($raw, 0, 5000),
                $response->failed()
                    ? 'ApiShip вернул HTTP '.$response->status().' без разбираемого ответа'
                    : 'Ответ ApiShip не является JSON',
                $durationMs,
            );
        }

        if ($response->failed()) {
            return new self(false, $response->status(), $json, null, self::describeError($json, $response->status()), $durationMs);
        }

        return new self(true, $response->status(), $json, null, null, $durationMs);
    }

    /**
     * Разобранный ответ или пустой массив — чтобы вызывающий код не проверял null на каждый чих.
     *
     * @return array<mixed>
     */
    public function data(): array
    {
        return $this->json ?? [];
    }

    /**
     * @return array{ok: bool, http_status: int|null, json: array<mixed>|null, raw: string|null, error: string|null, duration_ms: int}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'http_status' => $this->httpStatus,
            'json' => $this->json,
            'raw' => $this->raw,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * Человекочитаемая ошибка из формата ApiShip {code, message, description, errors[]}.
     *
     * Массив `errors` важнее общего `message`: именно там лежит причина отказа
     * конкретной службы доставки («не заполнен индекс», «превышен вес места»).
     *
     * @param  array<mixed>  $json
     */
    private static function describeError(array $json, int $status): string
    {
        $parts = [];

        foreach (['message', 'description'] as $key) {
            $value = $json[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        }

        $errors = $json['errors'] ?? null;

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_string($error)) {
                    $parts[] = $error;

                    continue;
                }

                if (! is_array($error)) {
                    continue;
                }

                $text = $error['message'] ?? $error['description'] ?? null;
                $field = $error['field'] ?? $error['fieldName'] ?? null;

                if (is_string($text) && $text !== '') {
                    $parts[] = is_string($field) && $field !== '' ? "{$field}: {$text}" : $text;
                }
            }
        }

        if ($parts === []) {
            return 'ApiShip вернул HTTP '.$status;
        }

        return implode('; ', array_unique($parts));
    }
}
