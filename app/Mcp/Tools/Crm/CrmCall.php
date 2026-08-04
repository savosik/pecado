<?php

namespace App\Mcp\Tools\Crm;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Выполнить операцию CRM по идентификатору.
 *
 * Без пометки IsReadOnly намеренно: через этот инструмент проходят и записи,
 * и клиент MCP должен видеть разницу между «посмотреть» и «сделать».
 */
class CrmCall extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-call';

    protected string $description = 'Выполнить операцию CRM: чтение или запись. '
        .'Операции записи необратимы и выполняются от имени сотрудника, которому выдан токен — '
        .'в ленте клиента запись будет видна как сделанная им. Схема аргументов — в crm-describe.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('Идентификатор операции из crm-catalog, например comment.create.')
                ->required(),
            'arguments' => $schema->object()
                ->description('Аргументы операции по схеме из crm-describe.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $arguments = $request->get('arguments', []);

        return $this->execute(
            trim((string) $request->string('operation')),
            is_array($arguments) ? $arguments : [],
        );
    }
}
