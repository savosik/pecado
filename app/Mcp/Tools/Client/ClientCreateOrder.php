<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Ярлык: заказ по списку товаров. Ключ идемпотентности обязателен на уровне схемы.
 */
class ClientCreateOrder extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-create-order';

    protected string $description = 'Создать заказ по списку {identifier, quantity}. Заказ уходит в 1С и в сборку — '
        .'«пробных» заказов нет. Недоступные позиции не блокируют заказ, а перечисляются в meta.not_accepted / meta.partial. '
        .'При нескольких юрлицах без основного нужен company_id — спросите у человека. '
        .'idempotency_key обязателен: при обрыве повторяйте с тем же ключом.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'products' => $schema->array()->description('Позиции: [{identifier, quantity}].')->required(),
            'idempotency_key' => $schema->string()->description('Уникальный ключ этого заказа (например, UUID).')->required(),
            'company_id' => $schema->integer()->description('Юрлицо-покупатель; по умолчанию основная компания.'),
            'delivery_method' => $schema->string()->description('delivery или pickup.'),
            'address' => $schema->string()->description('Адрес доставки.'),
            'comment' => $schema->string()->description('Комментарий к заказу.'),
            'apply_promotions' => $schema->boolean()->description('Начислить акции по принятым позициям (платные промо-позиции подлежат оплате).'),
            'reserve' => $schema->boolean()->description('Поставить складскую часть в резерв (только участникам режима).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = array_filter([
            'products' => $request->get('products'),
            'company_id' => $request->get('company_id'),
            'delivery_method' => $request->get('delivery_method'),
            'address' => $request->get('address'),
            'comment' => $request->get('comment'),
            'apply_promotions' => $request->get('apply_promotions'),
            'reserve' => $request->get('reserve'),
        ], fn ($v) => $v !== null);

        return $this->execute('orders.create', $args, (string) $request->get('idempotency_key'));
    }
}
