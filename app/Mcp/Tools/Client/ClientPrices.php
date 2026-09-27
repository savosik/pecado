<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: цены и остатки по списку товаров одним вызовом.
 */
#[IsReadOnly]
class ClientPrices extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-prices';

    protected string $description = 'Цены клиента и остатки по региону для списка товаров (до 500): uuid 1С, код, артикул '
        .'или штрихкод. Ненайденные и неоднозначные идентификаторы — в meta. Один вызов вместо catalog.prices + catalog.stocks.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'identifiers' => $schema->array()
                ->description('Идентификаторы товаров: коды, артикулы, штрихкоды или uuid 1С.')
                ->required(),
            'currency' => $schema->string()->description('Код валюты ответа, по умолчанию валюта региона клиента.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $identifiers = $request->get('identifiers', []);
        $identifiers = is_array($identifiers) ? array_values($identifiers) : [];
        $args = ['identifiers' => $identifiers];

        if ($request->get('currency')) {
            $args['currency'] = (string) $request->get('currency');
        }

        $prices = $this->run('catalog.prices', $args);

        if ($prices instanceof Response) {
            return $prices;
        }

        $stocks = $this->run('catalog.stocks', ['identifiers' => $identifiers]);

        if ($stocks instanceof Response) {
            return $stocks;
        }

        $stockByCode = [];

        foreach ($stocks['data'] ?? [] as $row) {
            $stockByCode[$row['code'].'|'.$row['sku']] = $row;
        }

        $merged = array_map(function (array $row) use ($stockByCode): array {
            $stock = $stockByCode[$row['code'].'|'.$row['sku']] ?? [];

            return $row + [
                'available' => $stock['available'] ?? 0,
                'preorder' => $stock['preorder'] ?? 0,
                'preorder_lead_days' => $stock['preorder_lead_days'] ?? null,
            ];
        }, $prices['data'] ?? []);

        return $this->payload(['data' => $merged, 'meta' => $prices['meta'] ?? []]);
    }
}
