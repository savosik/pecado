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
        {--strict : Вернуть код ошибки, если есть таблицы/столбцы без комментария (для CI)}';

    protected $description = 'Проверить покрытие таблиц и столбцов БД комментариями';

    public function handle(): int
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->warn("Аудит поддерживается только на MySQL/MariaDB (текущий драйвер: {$driver}). Пропуск.");

            return self::SUCCESS;
        }

        $database = DB::getDatabaseName();

        $tablesWithout = DB::table('information_schema.tables')
            ->where('table_schema', $database)
            ->where('table_type', 'BASE TABLE')
            ->where(fn ($q) => $q->whereNull('table_comment')->orWhere('table_comment', ''))
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();

        // Генерируемым (VIRTUAL/STORED GENERATED) столбцам COMMENT задать нельзя;
        // у них непустой generation_expression. DEFAULT_GENERATED (напр. DEFAULT CURRENT_TIMESTAMP)
        // сюда НЕ попадает — такие столбцы комментировать нужно.
        $notGenerated = fn ($q) => $q->whereNull('generation_expression')->orWhere('generation_expression', '');

        $columnsWithout = DB::table('information_schema.columns')
            ->where('table_schema', $database)
            ->where(fn ($q) => $q->whereNull('column_comment')->orWhere('column_comment', ''))
            ->where($notGenerated)
            ->orderBy('table_name')
            ->orderBy('ordinal_position')
            ->get(['table_name', 'column_name']);

        $tablesTotal = DB::table('information_schema.tables')
            ->where('table_schema', $database)->where('table_type', 'BASE TABLE')->count();
        $columnsTotal = DB::table('information_schema.columns')
            ->where('table_schema', $database)->where($notGenerated)->count();

        if ($tablesWithout !== []) {
            $this->newLine();
            $this->error('Таблицы без комментария ('.count($tablesWithout).'):');
            $this->line('  '.implode(', ', $tablesWithout));
        }

        if ($columnsWithout->isNotEmpty()) {
            $this->newLine();
            $this->error('Столбцы без комментария ('.$columnsWithout->count().'):');
            $this->table(
                ['Таблица', 'Столбец'],
                $columnsWithout->map(fn ($r) => [$r->table_name, $r->column_name])->all(),
            );
        }

        $this->newLine();
        $this->info(sprintf(
            'Покрытие: таблицы %d/%d, столбцы %d/%d.',
            $tablesTotal - count($tablesWithout), $tablesTotal,
            $columnsTotal - $columnsWithout->count(), $columnsTotal,
        ));

        $hasGaps = $tablesWithout !== [] || $columnsWithout->isNotEmpty();

        if (! $hasGaps) {
            $this->info('Все таблицы и столбцы прокомментированы. ✅');

            return self::SUCCESS;
        }

        if ($this->option('strict')) {
            $this->newLine();
            $this->error('Есть незадокументированные таблицы/столбцы. Добавьте ->comment() в миграцию.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('Есть незадокументированные таблицы/столбцы. Добавьте ->comment() в миграцию.');

        return self::SUCCESS;
    }
}
