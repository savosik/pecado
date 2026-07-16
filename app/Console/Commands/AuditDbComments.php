<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Аудит покрытия БД комментариями. Проверяет, что у каждой таблицы и каждого столбца
 * есть COMMENT — чтобы ИИ-агент через SHOW FULL COLUMNS / information_schema мог понимать
 * назначение данных без чтения кода.
 *
 * Комментарии проставляются штатно в миграциях (Blueprint ->comment()), а эта команда —
 * страховочная сеть: показывает пробелы. С флагом --strict возвращает ненулевой код (для CI).
 *
 * Работает только на MySQL/MariaDB (SQLite не хранит комментарии). Генерируемые (GENERATED)
 * столбцы игнорируются — им COMMENT задать нельзя.
 */
class AuditDbComments extends Command
{
    protected $signature = 'db:comments:audit
        {--connection=* : Коннекты для проверки (по умолчанию все БД проекта)}
        {--strict : Вернуть код ошибки, если есть таблицы/столбцы без комментария (для CI)}';

    protected $description = 'Проверить покрытие таблиц и столбцов БД комментариями';

    /**
     * Коннекты с реальными БД проекта.
     *
     * Перебирать config('database.connections') автоматически нельзя: там лежат ещё
     * стоковые заготовки Laravel (mariadb, pgsql, sqlsrv), которые никуда не ведут,
     * а отбор по driver=mysql зацепил бы мёртвый mariadb и дублировал бы основную БД.
     *
     * До 2026-07-16 команда ходила только по дефолтному коннекту и поэтому рапортовала
     * 100% покрытие, не видя pecado_prices — а та была не прокомментирована вовсе.
     */
    private const AUDITED_CONNECTIONS = ['mysql', 'prices'];

    public function handle(): int
    {
        $connections = $this->option('connection') ?: self::AUDITED_CONNECTIONS;

        $hasGaps = false;
        foreach ($connections as $name) {
            if ($this->auditConnection($name)) {
                $hasGaps = true;
            }
        }

        if (! $hasGaps) {
            $this->newLine();
            $this->info('Все таблицы и столбцы прокомментированы. ✅');

            return self::SUCCESS;
        }

        $this->newLine();
        $message = 'Есть незадокументированные таблицы/столбцы. Добавьте ->comment() в миграцию.';

        if ($this->option('strict')) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->warn($message);

        return self::SUCCESS;
    }

    /**
     * @return bool есть ли пробелы (или коннект недоступен — непроверенную БД
     *              считаем непокрытой, иначе --strict молча зеленеет)
     */
    private function auditConnection(string $name): bool
    {
        try {
            $connection = DB::connection($name);
            $driver = $connection->getDriverName();
        } catch (\Throwable $e) {
            $this->error("Коннект «{$name}» недоступен: {$e->getMessage()}");

            return true;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn("Коннект «{$name}»: аудит поддерживается только на MySQL/MariaDB (драйвер: {$driver}). Пропуск.");

            return false;
        }

        $database = $connection->getDatabaseName();

        // Генерируемым (VIRTUAL/STORED GENERATED) столбцам COMMENT задать нельзя;
        // у них непустой generation_expression. DEFAULT_GENERATED (напр. DEFAULT CURRENT_TIMESTAMP)
        // сюда НЕ попадает — такие столбцы комментировать нужно.
        $notGenerated = fn ($q) => $q->whereNull('generation_expression')->orWhere('generation_expression', '');

        // Псевдонимы обязательны: MySQL отдаёт столбцы information_schema в ВЕРХНЕМ
        // регистре (TABLE_NAME) независимо от того, как они написаны в SELECT. Без них
        // pluck('table_name') возвращает null'ы, а $row->table_name роняет команду.
        // Раньше это не всплывало: обе ветки — мёртвый код, пока покрытие было 100%.
        try {
            $tablesWithout = $connection->table('information_schema.tables')
                ->where('table_schema', $database)
                ->where('table_type', 'BASE TABLE')
                ->where(fn ($q) => $q->whereNull('table_comment')->orWhere('table_comment', ''))
                ->orderBy('table_name')
                ->pluck('table_name as tbl')
                ->all();

            $columnsWithout = $connection->table('information_schema.columns')
                ->where('table_schema', $database)
                ->where(fn ($q) => $q->whereNull('column_comment')->orWhere('column_comment', ''))
                ->where($notGenerated)
                ->orderBy('table_name')
                ->orderBy('ordinal_position')
                ->get(['table_name as tbl', 'column_name as col']);

            $tablesTotal = $connection->table('information_schema.tables')
                ->where('table_schema', $database)->where('table_type', 'BASE TABLE')->count();
            $columnsTotal = $connection->table('information_schema.columns')
                ->where('table_schema', $database)->where($notGenerated)->count();
        } catch (\Throwable $e) {
            $this->error("Коннект «{$name}» недоступен: {$e->getMessage()}");

            return true;
        }

        $this->newLine();
        $this->line("<options=bold>Коннект «{$name}» → БД {$database}</>");

        if ($tablesWithout !== []) {
            $this->error('Таблицы без комментария ('.count($tablesWithout).'):');
            $this->line('  '.implode(', ', $tablesWithout));
        }

        if ($columnsWithout->isNotEmpty()) {
            $this->error('Столбцы без комментария ('.$columnsWithout->count().'):');
            $this->table(
                ['Таблица', 'Столбец'],
                $columnsWithout->map(fn ($r) => [$r->tbl, $r->col])->all(),
            );
        }

        $this->info(sprintf(
            'Покрытие: таблицы %d/%d, столбцы %d/%d.',
            $tablesTotal - count($tablesWithout), $tablesTotal,
            $columnsTotal - $columnsWithout->count(), $columnsTotal,
        ));

        return $tablesWithout !== [] || $columnsWithout->isNotEmpty();
    }
}
