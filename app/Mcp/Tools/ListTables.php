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
 * Список доступных агенту таблиц с комментариями.
 *
 * Схема не хранится в коде и не требует обновления при миграциях: читается из
 * information_schema в момент вызова. Новая таблица появится здесь сама, как только
 * получит грант (bi:sync-grants после migrate).
 */
#[IsReadOnly]
class ListTables extends Tool
{
    protected string $description = 'Список таблиц и вьюх, доступных для отчётов, с описанием назначения каждой. '
        .'Начинать работу следует отсюда: описания объясняют, что где лежит, без чтения кода.';

    public function __construct(private readonly ReadOnlySqlRunner $runner) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'database' => $schema->string()
                ->description("База: 'main' — заказы, товары, клиенты; 'prices' — индивидуальные цены партнёров. По умолчанию main."),
        ];
    }

    public function handle(Request $request): Response
    {
        $database = (string) ($request->get('database') ?: 'main');

        try {
            $connection = DB::connection($this->runner->connectionFor($database));
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        // Показываем ровно то, к чему есть доступ: information_schema сама фильтрует
        // выдачу по правам текущего пользователя, поэтому таблицы с секретами
        // (sessions, api_tokens и пр.) сюда физически не попадут — их не нужно
        // вычищать вручную, и рассинхрона со списком грантов быть не может.
        $rows = $connection->select(<<<'SQL'
            SELECT table_name AS tbl, table_type AS kind, table_comment AS descr, table_rows AS approx_rows
            FROM information_schema.tables
            WHERE table_schema IN (DATABASE(), 'analytics')
            ORDER BY table_schema, table_name
        SQL);

        $viewDescriptions = $this->descriptionsForViews($database, $rows);

        $tables = array_map(fn ($r) => [
            'table' => $r->tbl,
            'rows_approx' => (int) $r->approx_rows,
            'description' => $viewDescriptions[$r->tbl] ?? ($r->descr ?: null),
        ], $rows);

        return Response::json([
            'database' => $database,
            'note' => $database === 'prices'
                ? 'Прайс живёт на отдельном сервере MySQL: JOIN с таблицами main невозможен, связывать данные нужно двумя запросами.'
                : 'Таблицы с префиксом v_ — вьюхи схемы analytics: то же содержимое без секретных колонок (пароли, токены).',
            'tables' => $tables,
        ]);
    }

    /**
     * Описания для вьюх v_* — из таблиц, на которых они построены.
     *
     * Своего комментария у вьюхи в MySQL не бывает: information_schema возвращает
     * для неё буквально 'VIEW'. Без этой подстановки агент увидел бы у v_users
     * описание «VIEW» вместо назначения таблицы — то есть ровно там, где описание
     * нужнее всего, его бы и не было.
     *
     * @param  list<object>  $rows
     * @return array<string, string>
     */
    private function descriptionsForViews(string $database, array $rows): array
    {
        $sources = [];
        foreach ($rows as $row) {
            if ($row->kind === 'VIEW' && str_starts_with($row->tbl, 'v_')) {
                $sources[$row->tbl] = substr($row->tbl, 2);
            }
        }

        if ($sources === []) {
            return [];
        }

        // Коннект приложения, а не bi_agent: у последнего нет прав на базовые
        // таблицы, и их описания он не увидит в принципе.
        $comments = DB::connection($this->runner->sourceConnectionFor($database))
            ->table('information_schema.tables')
            ->whereRaw('table_schema = DATABASE()')
            ->whereIn('table_name', array_values($sources))
            ->pluck('table_comment as descr', 'table_name as tbl');

        $result = [];
        foreach ($sources as $view => $baseTable) {
            if ($comment = $comments[$baseTable] ?? null) {
                $result[$view] = $comment;
            }
        }

        return $result;
    }
}
