<?php

namespace App\Mcp\Tools\Crm;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Ярлык: комментарий по клиенту.
 *
 * Самый частый сценарий записи — «занеси, о чём договорились». Ради него
 * не должно требоваться три вызова и знание того, что привязка полиморфна.
 */
class CrmAddComment extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-add-comment';

    protected string $description = 'Оставить комментарий по клиенту. Запись необратима и попадёт '
        .'в ленту клиента от имени сотрудника, которому выдан токен.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()
                ->description('Идентификатор клиента.')
                ->required(),
            'body' => $schema->string()
                ->description('Текст комментария, до 5000 символов.')
                ->required(),
            'pin' => $schema->boolean()
                ->description('Закрепить комментарий в начале ленты.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->execute('comment.create', [
            'entity_type' => 'client',
            'entity_id' => (int) $request->get('client_id'),
            'body' => (string) $request->string('body'),
            'is_pinned' => (bool) $request->get('pin', false),
        ]);
    }
}
