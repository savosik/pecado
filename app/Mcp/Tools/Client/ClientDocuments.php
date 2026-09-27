<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: документы за период со ссылками на файлы.
 */
#[IsReadOnly]
class ClientDocuments extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-documents';

    protected string $description = 'Печатные формы из 1С (счета, УПД, акты сверки…) за период, с временными ссылками на файлы, '
        .'которые можно передать человеку. Доступно, когда клиенту открыт раздел «Документы».';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->array()->description('Виды документов, например ["upd", "invoice"]. Пусто — все.'),
            'date_from' => $schema->string()->description('С даты YYYY-MM-DD.'),
            'date_to' => $schema->string()->description('По дату YYYY-MM-DD.'),
            'company_id' => $schema->integer()->description('Контрагент клиента.'),
            'number' => $schema->string()->description('Номер документа.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $args = array_filter([
            'type' => $request->get('type'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'company_id' => $request->get('company_id'),
            'number' => $request->get('number'),
            'per_page' => 50,
        ], fn ($v) => $v !== null && $v !== []);

        $list = $this->run('documents.list', $args);

        if ($list instanceof Response) {
            return $list;
        }

        $rows = [];

        foreach ($list['data'] ?? [] as $row) {
            $link = $this->run('documents.link', ['document' => $row['id']]);
            $row['download'] = $link instanceof Response ? null : ($link['data'] ?? null);
            $rows[] = $row;
        }

        return $this->payload(['data' => $rows, 'meta' => $list['meta'] ?? []]);
    }
}
