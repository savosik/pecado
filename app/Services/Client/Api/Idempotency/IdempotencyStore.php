<?php

namespace App\Services\Client\Api\Idempotency;

use App\Models\ClientApiIdempotencyKey;
use App\Models\User;
use App\Services\Client\Api\Operation;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Протокол ключа идемпотентности вокруг пишущей операции.
 *
 * Первый вызов с ключом занимает строку `in_progress` (уникальный индекс отбивает
 * гонку двух параллельных повторов), после обработчика строка становится `done`
 * с сохранённым ответом. Повтор с тем же ключом и тем же телом возвращает этот
 * ответ, не трогая обработчик; повтор с другим телом отклоняется — ключ не
 * «имя запроса», а его отпечаток. Провал обработчика освобождает ключ: повтор
 * после ошибки должен выполниться.
 */
class IdempotencyStore
{
    /**
     * Занять ключ. Возвращает сохранённый ответ, если запрос уже выполнен.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null ответ прежнего вызова либо null — выполнять
     *
     * @throws IdempotencyConflict
     */
    public function begin(User $user, Operation $operation, string $key, array $args): ?array
    {
        $hash = $this->hash($args);

        try {
            ClientApiIdempotencyKey::query()->create([
                'user_id' => $user->getKey(),
                'operation' => $operation->id,
                'key' => $key,
                'request_hash' => $hash,
                'status' => ClientApiIdempotencyKey::STATUS_IN_PROGRESS,
                'created_at' => now(),
                'expires_at' => now()->addHours(ClientApiIdempotencyKey::TTL_HOURS),
            ]);

            return null;
        } catch (UniqueConstraintViolationException) {
            // Ключ уже есть: либо прежний вызов завершён, либо ещё идёт.
        }

        $existing = $this->find($user, $operation, $key);

        if ($existing === null) {
            // Строку успели удалить (провал параллельного вызова) — занимаем заново.
            return $this->begin($user, $operation, $key, $args);
        }

        if ($existing->expires_at->isPast()) {
            $existing->delete();

            return $this->begin($user, $operation, $key, $args);
        }

        if ($existing->request_hash !== $hash) {
            throw IdempotencyConflict::reused();
        }

        if (! $existing->isDone()) {
            throw IdempotencyConflict::inProgress();
        }

        $response = $existing->response ?? [];
        $response['meta'] = ($response['meta'] ?? []) + ['idempotent_replay' => true];

        return $response;
    }

    /**
     * Сохранить ответ выполненной операции.
     *
     * @param  array<string, mixed>  $response
     */
    public function complete(User $user, Operation $operation, string $key, array $response, int $statusCode = 200): void
    {
        ClientApiIdempotencyKey::query()
            ->where('user_id', $user->getKey())
            ->where('operation', $operation->id)
            ->where('key', $key)
            ->update([
                'status' => ClientApiIdempotencyKey::STATUS_DONE,
                'status_code' => $statusCode,
                'response' => json_encode($response, JSON_UNESCAPED_UNICODE),
            ]);
    }

    /**
     * Освободить ключ после провала: повтор должен выполниться.
     */
    public function fail(User $user, Operation $operation, string $key): void
    {
        ClientApiIdempotencyKey::query()
            ->where('user_id', $user->getKey())
            ->where('operation', $operation->id)
            ->where('key', $key)
            ->where('status', ClientApiIdempotencyKey::STATUS_IN_PROGRESS)
            ->delete();
    }

    private function find(User $user, Operation $operation, string $key): ?ClientApiIdempotencyKey
    {
        return ClientApiIdempotencyKey::query()
            ->where('user_id', $user->getKey())
            ->where('operation', $operation->id)
            ->where('key', $key)
            ->first();
    }

    /**
     * Отпечаток аргументов: порядок ключей не важен, служебные аргументы раннера — тоже.
     *
     * @param  array<string, mixed>  $args
     */
    public function hash(array $args): string
    {
        unset($args['_company']);

        return hash('sha256', json_encode($this->normalize($args), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->normalize($v), $value);
        }

        ksort($value);

        return array_map(fn ($v) => $this->normalize($v), $value);
    }
}
