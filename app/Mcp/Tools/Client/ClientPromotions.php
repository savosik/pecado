<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: какие акции действуют для клиента прямо сейчас.
 */
#[IsReadOnly]
class ClientPromotions extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-promotions';

    protected string $description = 'Действующие для клиента акции: описание, период, условия и что он получит. '
        .'С slug — одна акция с полным текстом и её товарами. Вызывайте, прежде чем отвечать об акциях и скидках.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Адрес акции из списка — чтобы получить её целиком с товарами.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $slug = $request->get('slug');

        if (! $slug) {
            $list = $this->run('promotions.list', ['per_page' => 100]);

            return $list instanceof Response ? $list : $this->payload($list);
        }

        $promotion = $this->run('promotions.get', ['slug' => $slug]);

        if ($promotion instanceof Response) {
            return $promotion;
        }

        $products = $this->run('promotions.products', ['slug' => $slug, 'per_page' => 100]);

        return $this->payload([
            'promotion' => $promotion['data'] ?? null,
            'products' => $products instanceof Response ? null : ($products['data'] ?? []),
            'products_has_more' => $products instanceof Response ? false : (bool) ($products['meta']['has_more'] ?? false),
        ]);
    }
}
