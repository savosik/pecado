<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: «где мой заказ» одним вызовом.
 */
#[IsReadOnly]
class ClientOrderStatus extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-order-status';

    protected string $description = 'Карточка заказа по id, номеру или uuid: статус, состав (с отменёнными в 1С строками), '
        .'реализации, история статусов. Список заказов — операция orders.list через client-call.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('id, номер (1С или сайта) либо uuid заказа.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->execute('orders.get', ['order' => trim((string) $request->string('order'))]);
    }
}
