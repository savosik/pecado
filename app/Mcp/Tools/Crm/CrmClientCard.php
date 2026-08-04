<?php

namespace App\Mcp\Tools\Crm;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: карточка клиента одним вызовом.
 *
 * Ярлыки существуют ради частых сценариев: платить тремя вызовами
 * (каталог → схема → вызов) за «покажи клиента» разговор не выдерживает.
 * Под капотом — та же операция реестра, никакой второй логики.
 */
#[IsReadOnly]
class CrmClientCard extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-client-card';

    protected string $description = 'Карточка клиента: контакты, менеджер, профиль, план и факт месяца. '
        .'Клиент вне зоны ответственности сотрудника недоступен.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()
                ->description('Идентификатор клиента. Найти его можно операцией client.list через crm-call.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->execute('client.show', ['client' => (int) $request->get('client_id')]);
    }
}
