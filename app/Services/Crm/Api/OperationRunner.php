<?php

namespace App\Services\Crm\Api;

use App\Models\User;
use App\Support\Crm\CrmSource;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Единственная точка выполнения операции CRM.
 *
 * Через неё проходят оба канала — REST `/api/crm/*` и MCP `/mcp/crm`, — поэтому
 * право, проверка аргументов и аудит написаны один раз. Разведи их по каналам,
 * и рано или поздно один из двух начал бы пускать туда, куда второй не пускает.
 */
class OperationRunner
{
    /** Канал аудита: кто, что и с какими аргументами делал через агентский гейт. */
    public const LOG_CHANNEL = 'crm-agent';

    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws OperationDenied нет права или операция закрыта для агента
     * @throws \Illuminate\Validation\ValidationException аргументы не прошли проверку
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException запись вне скоупа сотрудника
     * @throws \Illuminate\Auth\Access\AuthorizationException политика отказала
     * @throws \InvalidArgumentException|\RuntimeException бизнес-правило отказало
     */
    public function run(Operation $operation, User $actor, array $args): array
    {
        $this->authorize($operation, $actor);

        $validated = Validator::make(
            $args,
            $operation->validationRules(),
            [],
            $operation->attributes(),
        )->validate();

        $handler = $this->container->make($operation->handler[0]);
        $method = $operation->handler[1];

        try {
            $result = $handler->{$method}($actor, new OperationInput($validated));
        } catch (Throwable $e) {
            // Пишем и провал: «агент попробовал и не смог» — такая же часть
            // разбора инцидента, как удавшаяся запись.
            $this->audit($operation, $actor, $validated, $e);

            throw $e;
        }

        $this->audit($operation, $actor, $validated);

        return $result;
    }

    /**
     * @throws OperationDenied
     */
    private function authorize(Operation $operation, User $actor): void
    {
        if (! $operation->agentAllowed) {
            throw new OperationDenied(
                $operation->deniedReason ?? 'Операция «'.$operation->id.'» недоступна через агентский доступ.'
            );
        }

        if (! $actor->can($operation->permission)) {
            throw new OperationDenied(
                'Нет права «'.$operation->permission.'»: операция «'.$operation->id.'» недоступна.'
            );
        }
    }

    /**
     * Аудит операций записи. Чтение не логируем: агент читает постоянно, и в этом
     * шуме потерялись бы те несколько строк, ради которых журнал и заводится.
     *
     * @param  array<string, mixed>  $args
     */
    private function audit(Operation $operation, User $actor, array $args, ?Throwable $error = null): void
    {
        if (! $operation->mutating) {
            return;
        }

        Log::channel(self::LOG_CHANNEL)->info($operation->id, array_filter([
            'operation' => $operation->id,
            'source' => CrmSource::current(),
            'token' => CrmSource::label(),
            'user_id' => $actor->getKey(),
            'user' => $actor->name,
            'args' => $args,
            'error' => $error?->getMessage(),
        ], fn ($value) => $value !== null));
    }
}
