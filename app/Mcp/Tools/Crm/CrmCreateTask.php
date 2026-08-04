<?php

namespace App\Mcp\Tools\Crm;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Ярлык: поставить задачу, при желании привязав к клиенту.
 */
class CrmCreateTask extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-create-task';

    protected string $description = 'Поставить задачу. Без указания клиента задача живёт сама по себе. '
        .'Исполнителем по умолчанию становится сотрудник, которому выдан токен.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Что нужно сделать.')
                ->required(),
            'client_id' => $schema->integer()
                ->description('Клиент, к которому привязать задачу.'),
            'description' => $schema->string()
                ->description('Подробности.'),
            'due_at' => $schema->string()
                ->description('Дедлайн: ГГГГ-ММ-ДД или ГГГГ-ММ-ДД ЧЧ:ММ.'),
            'priority' => $schema->string()
                ->description('Приоритет: low, normal, high.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = array_filter([
            'title' => (string) $request->string('title'),
            'description' => $request->get('description'),
            'due_at' => $request->get('due_at'),
            'priority' => $request->get('priority'),
        ], fn ($value) => $value !== null && $value !== '');

        $clientId = (int) $request->get('client_id', 0);

        if ($clientId > 0) {
            $args['entity_type'] = 'client';
            $args['entity_id'] = $clientId;
        }

        return $this->execute('task.create', $args);
    }
}
