<?php

namespace App\Mcp\Tools\Client;

use App\Services\Client\Api\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Полная схема аргументов одной операции — из того же реестра, что и проверки.
 */
#[IsReadOnly]
class ClientDescribe extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-describe';

    protected string $description = 'Схема аргументов операции: какие поля обязательны, какие значения допустимы, '
        .'что операция делает и требует ли ключ идемпотентности. Вызывать перед client-call.';

    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('Идентификатор операции из client-catalog, например orders.create.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить клиента по токену.');
        }

        $id = trim((string) $request->string('operation'));
        $operation = $this->registry->find($id);

        if ($operation === null) {
            return Response::error("Операции «{$id}» нет. Полный список — в client-catalog.");
        }

        return $this->payload($operation->catalogEntry($actor) + [
            'description' => $operation->description,
            'schema' => $operation->jsonSchema(),
        ]);
    }
}
