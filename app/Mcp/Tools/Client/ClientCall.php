<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Выполнить операцию клиентского API по идентификатору.
 *
 * Без IsReadOnly намеренно: через этот инструмент проходят и записи, и клиент
 * MCP должен видеть разницу между «посмотреть» и «сделать».
 */
class ClientCall extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-call';

    protected string $description = 'Выполнить операцию кабинета: чтение или запись. Записи необратимы и идут от имени '
        .'клиента — заказ уходит в 1С и в сборку. Для операций с idempotency_required=true передайте idempotency_key; '
        .'при обрыве повторяйте с тем же ключом. Схема аргументов — в client-describe.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()
                ->description('Идентификатор операции из client-catalog, например orders.get.')
                ->required(),
            'arguments' => $schema->object()
                ->description('Аргументы операции по схеме из client-describe.'),
            'idempotency_key' => $schema->string()
                ->description('Уникальный ключ запроса для пишущих операций (например, UUID). Повтор с тем же ключом не создаст дубль.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $arguments = $request->get('arguments', []);

        return $this->execute(
            trim((string) $request->string('operation')),
            is_array($arguments) ? $arguments : [],
            $request->get('idempotency_key'),
        );
    }
}
