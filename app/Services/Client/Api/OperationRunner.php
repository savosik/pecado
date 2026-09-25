<?php

namespace App\Services\Client\Api;

use App\Models\User;
use App\Services\Client\Api\Idempotency\IdempotencyConflict;
use App\Services\Client\Api\Idempotency\IdempotencyStore;
use App\Services\Client\Api\Usage\UsageContext;
use App\Support\Client\ClientApiSource;
use App\Support\OperationApi\OperationDenied;
use App\Support\OperationApi\OperationInput;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Единственная точка выполнения операции клиентского API.
 *
 * Через неё проходят оба канала — REST `/api/client/v1/*` и MCP `/mcp/client`, —
 * поэтому гейт, проверка аргументов, контекст юрлица и аудит написаны один раз.
 *
 * Порядок: доступность → гейт раздела → валидация → юрлицо → ключ идемпотентности
 * → обработчик → сохранение ответа → аудит. Ключ занимается до обработчика, чтобы
 * два параллельных повтора не прошли оба; провал обработчика ключ освобождает.
 */
class OperationRunner
{
    /** Канал аудита пишущих операций клиента. */
    public const LOG_CHANNEL = 'client-agent';

    /** Ключ, под которым обработчик получает разрешённое юрлицо. */
    public const COMPANY_ARG = '_company';

    public function __construct(
        private readonly Container $container,
        private readonly CompanyContext $companies,
        private readonly IdempotencyStore $idempotency,
        private readonly UsageContext $usage,
        private readonly \App\Services\Assistant\Confirmations\ConfirmationGate $confirmations,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws OperationDenied операция закрыта или раздел выключен
     * @throws \Illuminate\Validation\ValidationException аргументы не прошли проверку
     * @throws CompanyRequired юрлицо не выбрано, а вариантов несколько
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException запись не своя
     * @throws \InvalidArgumentException|\RuntimeException бизнес-правило отказало
     */
    public function run(Operation $operation, User $actor, array $args, ?string $idempotencyKey = null): array
    {
        // Журнал вызовов: операция отмечается до гейта — отказ по закрытому
        // разделу тоже «задача», которую агент пытался решить.
        $this->usage->operation($operation);

        $this->authorize($operation, $actor);

        $validated = Validator::make(
            $args,
            $operation->validationRules(),
            [],
            $operation->attributes(),
        )->validate();

        if ($operation->companyScoped) {
            $validated[self::COMPANY_ARG] = $this->companies->resolve(
                $actor,
                isset($validated['company_id']) ? (int) $validated['company_id'] : null,
                isset($validated['inn']) ? (string) $validated['inn'] : null,
            );

            $this->usage->operation($operation, $validated[self::COMPANY_ARG]);
        }

        // Чат-помощник (assist-04): необратимая операция выполняется только по
        // подтверждению клиента кнопкой; ключ идемпотентности — из подтверждения,
        // чтобы повтор модели после кнопки не создал дубль.
        $confirmation = null;

        if ($this->confirmations->requires($operation)) {
            $confirmation = $this->confirmations->pass($actor, $operation, $validated);
            $idempotencyKey = $confirmation->idempotency_key;
        }

        $useKey = $operation->idempotent && $idempotencyKey !== null;

        if ($operation->idempotencyRequired && $idempotencyKey === null) {
            throw IdempotencyConflict::required('Idempotency-Key');
        }

        if ($useKey) {
            $replay = $this->idempotency->begin($actor, $operation, $idempotencyKey, $validated);

            if ($replay !== null) {
                return $replay;
            }
        }

        $handler = $this->container->make($operation->handler[0]);
        $method = $operation->handler[1];

        try {
            $result = $handler->{$method}($actor, new OperationInput($validated));
        } catch (Throwable $e) {
            if ($useKey) {
                $this->idempotency->fail($actor, $operation, $idempotencyKey);
            }

            $this->audit($operation, $actor, $validated, $idempotencyKey, $e);

            throw $e;
        }

        if ($useKey) {
            $this->idempotency->complete($actor, $operation, $idempotencyKey, $result);
        }

        if ($confirmation !== null) {
            $this->confirmations->consumed($confirmation, $result);
        }

        $this->audit($operation, $actor, $validated, $idempotencyKey);

        return $result;
    }

    /**
     * @throws OperationDenied
     */
    private function authorize(Operation $operation, User $actor): void
    {
        if (! $operation->agentAllowed) {
            throw new OperationDenied(
                $operation->deniedReason ?? 'Операция «'.$operation->id.'» недоступна через API.'
            );
        }

        if (! $operation->gate->allows($actor)) {
            throw new GateClosed($operation->gate);
        }
    }

    /**
     * Аудит операций записи. Чтение не логируем: агент читает постоянно, и в этом
     * шуме потерялись бы строки, ради которых журнал заводится.
     *
     * @param  array<string, mixed>  $args
     */
    private function audit(Operation $operation, User $actor, array $args, ?string $idempotencyKey, ?Throwable $error = null): void
    {
        if (! $operation->mutating) {
            return;
        }

        $company = $args[self::COMPANY_ARG] ?? null;
        unset($args[self::COMPANY_ARG]);

        Log::channel(self::LOG_CHANNEL)->info($operation->id, array_filter([
            'operation' => $operation->id,
            'token_id' => ClientApiSource::tokenId(),
            'token' => ClientApiSource::tokenName(),
            'user_id' => $actor->getKey(),
            'user' => $actor->name,
            'company_id' => $company?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'args' => $args,
            'error' => $error?->getMessage(),
        ], fn ($value) => $value !== null));
    }
}
