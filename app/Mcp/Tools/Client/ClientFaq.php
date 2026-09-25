<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: ответы из FAQ и информационных страниц сайта.
 */
#[IsReadOnly]
class ClientFaq extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-faq';

    protected string $description = 'Частые вопросы с ответами и перечень информационных страниц (доставка, оплата, условия). '
        .'Вызывайте, прежде чем отвечать на общие вопросы о работе с Pecado; текст страницы — client-call pages.get.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Слово или фраза для поиска по вопросам и ответам. Пусто — весь FAQ.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $faq = $this->run('faq.list', array_filter(['q' => $request->get('q')]));

        if ($faq instanceof Response) {
            return $faq;
        }

        $pages = $this->run('pages.list', []);

        return $this->payload([
            'faq' => $faq['data'] ?? [],
            'pages' => $pages instanceof Response ? [] : ($pages['data'] ?? []),
        ]);
    }
}
