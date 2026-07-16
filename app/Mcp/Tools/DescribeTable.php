<?php

namespace App\Mcp\Tools;

use App\Services\Bi\ReadOnlySqlRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Колонки таблицы с комментариями и индексами.
 *
 * Комментарии здесь — не украшение: например, комментарий у orders.erp_created_at
 * («Дата создания заказа в 1С») удерживает агента от created_at, который на проде
 * равен дате импорта истории, а не дате документа. Без этого отчёт по периодам
 * тихо соврёт. Покрытие БД комментариями сплошное — см. .claude/rules/db-comments.md.
 */
#[IsReadOnly]
class DescribeTable extends Tool
{
    protected string $description = 'Колонки таблицы с описанием каждой, типы, индексы. '
        .'Вызывать перед написанием запроса: описания колонок объясняют смысл данных и подводные камни.';

    public function __construct(private readonly ReadOnlySqlRunner $runner) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('Имя таблицы или вьюхи (как в list-tables).')
                ->required(),
            'database' => $schema->string()
                ->description("База: 'main' (по умолчанию) или 'prices'."),
        ];
    }

    public function handle(Request $request): Response
    {
        $database = (string) ($request->get('database') ?: 'main');
        $table = trim((string) $request->string('table'));

        try {
            $connection = DB::connection($this->runner->connectionFor($database));
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        // Имя таблицы — связанный параметр, а не подстановка в строку: значение
        // приходит от модели, то есть в конечном счёте из чужого текста.
        $columns = $connection->select(<<<'SQL'
            SELECT column_name AS col, column_type AS type, is_nullable AS nullable,
                   column_key AS keyed, column_comment AS descr
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ?
            ORDER BY ordinal_position
        SQL, [$table]);

        if ($columns === []) {
            return Response::error(
                "Таблица «{$table}» не найдена или недоступна. Список доступных — в list-tables."
            );
        }

        $indexes = $connection->select(<<<'SQL'
            SELECT index_name AS name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols, non_unique AS non_uniq
            FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ?
            GROUP BY index_name, non_unique
        SQL, [$table]);

        return Response::json([
            'database' => $database,
            'table' => $table,
            'columns' => array_map(fn ($c) => [
                'name' => $c->col,
                'type' => $c->type,
                'nullable' => $c->nullable === 'YES',
                'key' => $c->keyed ?: null,
                'description' => $c->descr ?: null,
            ], $columns),
            'indexes' => array_map(fn ($i) => [
                'name' => $i->name,
                'columns' => explode(',', (string) $i->cols),
                'unique' => ! $i->non_uniq,
            ], $indexes),
        ]);
    }
}
