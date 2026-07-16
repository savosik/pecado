<?php

namespace App\Mcp\Tools;

use App\Services\Bi\ReadOnlySqlRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Выполнение произвольного SELECT под read-only пользователем.
 *
 * Вся защита — в ReadOnlySqlRunner: он владеет коннектом, поэтому таймаут выставляет
 * он, а не запрос. Здесь только приём параметров и понятные ошибки.
 */
#[IsReadOnly]
class RunQuery extends Tool
{
    protected string $description = 'Выполнить SELECT и получить строки. Доступ только на чтение. '
        .'Запрос к базе prices обязан содержать WHERE partner_id — иначе он читает все 17+ млн строк и будет прерван по таймауту.';

    public function __construct(private readonly ReadOnlySqlRunner $runner) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()
                ->description('SQL-запрос. Разрешены SELECT, WITH … SELECT, SHOW, EXPLAIN, DESCRIBE.')
                ->required(),
            'database' => $schema->string()
                ->description("База: 'main' (по умолчанию) — заказы, товары, клиенты; 'prices' — индивидуальные цены."),
            'limit' => $schema->integer()
                ->description('Максимум строк в ответе (по умолчанию и максимум '.ReadOnlySqlRunner::MAX_ROWS.').'),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            $result = $this->runner->select(
                database: (string) ($request->get('database') ?: 'main'),
                sql: (string) $request->string('sql'),
                limit: (int) ($request->get('limit') ?: ReadOnlySqlRunner::MAX_ROWS),
            );
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            // Таймаут — не поломка, а рабочая ситуация: подсказываем, что делать.
            if (str_contains($e->getMessage(), '3024')) {
                return Response::error(
                    'Запрос прерван по таймауту. Обычно причина — чтение таблицы целиком: '
                    .'добавьте условия (для prices обязателен WHERE partner_id) либо агрегируйте на стороне БД.'
                );
            }

            return Response::error('Ошибка запроса: '.$e->getMessage());
        }

        return Response::json($result);
    }
}
