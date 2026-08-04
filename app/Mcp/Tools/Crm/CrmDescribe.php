<?php

namespace App\Mcp\Tools\Crm;

use App\Services\Crm\Api\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Полная схема аргументов одной операции.
 *
 * Схема отдаётся из того же реестра, из которого построены проверки: описание
 * не может обещать аргумент, который сервер не примет.
 */
#[IsReadOnly]
class CrmDescribe extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-describe';

    protected string $description = 'Схема аргументов операции CRM: какие поля обязательны, '
        .'какие значения допустимы и что операция делает. Вызывать перед crm-call.';

    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('Идентификатор операции из crm-catalog, например client.profile.update.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить сотрудника по токену.');
        }

        $id = trim((string) $request->string('operation'));
        $operation = $this->registry->find($id);

        if ($operation === null) {
            return Response::error("Операции «{$id}» нет. Полный список — в crm-catalog.");
        }

        return $this->payload($operation->catalogEntry($actor) + [
            'description' => $operation->description,
            'schema' => $operation->jsonSchema(),
        ]);
    }
}
