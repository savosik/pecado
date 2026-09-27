<?php

namespace App\Services\Assistant\Confirmations;

use App\Models\ChatConfirmation;
use App\Models\ChatThread;
use App\Models\Company;
use App\Models\User;
use App\Services\Client\Api\Operation;
use App\Support\Client\ClientApiSource;
use Illuminate\Support\Str;

/**
 * Ворота необратимых операций для чата-помощника.
 *
 * Через MCP-коннектор вызовы инструментов делает сервер Anthropic, и встать
 * между моделью и операцией снаружи нельзя — поэтому ворота стоят внутри
 * раннера операций. Токен вида `assistant` + операция из списка
 * `assistant.confirm_operations` = нужна запись `chat_confirmations` со
 * статусом approved и тем же отпечатком аргументов. Нет — создаём pending и
 * отвечаем модели кодом `confirmation_required`; клиент жмёт кнопку, модель
 * повторяет вызов, ворота пропускают и выдают ключ идемпотентности.
 */
final class ConfirmationGate
{
    /** Сколько живёт карточка подтверждения. */
    private const TTL_HOURS = 2;

    public function requires(Operation $operation): bool
    {
        if (! ClientApiSource::isAssistant() || ! $operation->mutating) {
            return false;
        }

        return in_array($operation->id, (array) config('assistant.confirm_operations', []), true);
    }

    /**
     * Пропустить с одобренным подтверждением или остановить на карточке.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ConfirmationRequired
     */
    public function pass(User $actor, Operation $operation, array $validated): ChatConfirmation
    {
        $thread = ChatThread::query()
            ->open()
            ->where('user_id', $actor->getKey())
            ->where('token_id', ClientApiSource::tokenId())
            ->first();

        if ($thread === null) {
            throw new \RuntimeException('Токен помощника не привязан к открытому треду — операция не выполнена.');
        }

        $arguments = self::plainArguments($validated);
        $hash = ChatConfirmation::hashArguments($operation->id, $arguments);

        $existing = ChatConfirmation::query()
            ->where('thread_id', $thread->id)
            ->where('arguments_hash', $hash)
            ->latest('id')
            ->first();

        if ($existing !== null && $existing->isApproved()) {
            return $existing;
        }

        if ($existing !== null && $existing->isPending()) {
            throw new ConfirmationRequired($existing);
        }

        $fresh = ChatConfirmation::create([
            'thread_id' => $thread->id,
            'user_id' => $actor->getKey(),
            'operation' => $operation->id,
            'arguments' => $arguments,
            'arguments_hash' => $hash,
            'idempotency_key' => 'assist-'.Str::uuid(),
            'status' => ChatConfirmation::STATUS_PENDING,
            'summary' => $this->summary($operation, $validated, $arguments),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        throw new ConfirmationRequired($fresh);
    }

    /**
     * Операция выполнена по одобренному подтверждению: закрыть карточку.
     *
     * @param  array<string, mixed>  $result
     */
    public function consumed(ChatConfirmation $confirmation, array $result): void
    {
        $confirmation->forceFill([
            'status' => ChatConfirmation::STATUS_USED,
            'result' => self::compactResult($result),
        ])->save();
    }

    /**
     * Аргументы без служебного объекта юрлица: он не сериализуется и не
     * нужен для отпечатка — company_id уже в аргументах.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function plainArguments(array $validated): array
    {
        $plain = [];

        foreach ($validated as $key => $value) {
            if ($value instanceof Company) {
                $plain['company_id'] = (int) $value->getKey();

                continue;
            }

            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }

            $plain[$key] = $value;
        }

        return $plain;
    }

    /**
     * Что показать в карточке: название операции, юрлицо, аргументы.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function summary(Operation $operation, array $validated, array $arguments): array
    {
        $company = null;

        foreach ($validated as $value) {
            if ($value instanceof Company) {
                $company = ['id' => (int) $value->getKey(), 'name' => (string) ($value->name ?: $value->legal_name)];
            }
        }

        return [
            'label' => $operation->summary,
            'section' => $operation->section,
            'company' => $company,
            'arguments' => $arguments,
        ];
    }

    /**
     * Ответ операции в карточку — без вложенных простыней: заказ, номер, сумма.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function compactResult(array $result): array
    {
        $keep = [];

        foreach (['id', 'uuid', 'number', 'status', 'status_label', 'total', 'total_amount', 'items_count', 'message'] as $key) {
            if (array_key_exists($key, $result) && ! is_array($result[$key])) {
                $keep[$key] = $result[$key];
            }
        }

        foreach (['order', 'orders', 'return', 'question'] as $nested) {
            if (isset($result[$nested]) && is_array($result[$nested])) {
                $keep[$nested] = array_intersect_key($result[$nested], array_flip(['id', 'uuid', 'number', 'status', 'status_label', 'total', 'total_amount']));
            }
        }

        return $keep;
    }
}
