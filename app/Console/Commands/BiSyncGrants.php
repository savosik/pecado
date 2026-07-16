<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Генерирует SQL с правами read-only пользователя для BI и ИИ-агента отчётов.
 *
 * ПОЧЕМУ ТОЛЬКО ГЕНЕРИРУЕТ, А НЕ ПРИМЕНЯЕТ. Приложение ходит в БД под `pecado`:
 * у него ALL PRIVILEGES на свою базу, но нет GRANT OPTION (проверено — ERROR 1142),
 * а root-пароля в окружении приложения нет и быть не должно. Поэтому команда —
 * чистая функция от схемы: печатает SQL, применяет его root снаружи:
 *
 *   docker exec pecado-app php artisan bi:sync-grants --connection=mysql \
 *     | docker exec -i pecado-mysql mysql -uroot -p"$DB_ROOT_PASSWORD"
 *
 *   docker exec pecado-app php artisan bi:sync-grants --connection=prices \
 *     | docker exec -i pecado-mysql-prices mysql -uroot -p"$DB_PRICES_ROOT_PASSWORD"
 *
 * Коннекты разные, потому что это физически разные серверы MySQL — один поток SQL
 * на обе базы не применить.
 *
 * ЗАЧЕМ ЭТО ВООБЩЕ. Read-only пользователь и так не может писать (GRANT SELECT —
 * гарантия движка), но всё, что он прочитает, ИИ-агент унесёт в контекст модели.
 * Пароли и OAuth-токены там оказаться не должны, поэтому таблицы с секретными
 * колонками выдаются не напрямую, а через вьюхи без этих колонок.
 *
 * ПОЧЕМУ ШАБЛОН, А НЕ СПИСОК. Список секретов устаревает молча: при первом подходе
 * к задаче казалось, что секретных колонок три (users.*), а их шесть — нашлись ещё
 * entity_subscriptions.unsubscribe_token и живые OAuth-токены в social_accounts.
 * Через месяц миграция добавит седьмую, и про неё никто не вспомнит. Поэтому
 * прячется всё, что похоже на секрет, а исключения перечислены явно и обоснованы.
 */
class BiSyncGrants extends Command
{
    protected $signature = 'bi:sync-grants
        {--connection=mysql : Коннект, для схемы которого генерировать SQL (mysql|prices)}
        {--user=bi_agent : Имя read-only пользователя}
        {--host=% : Хост пользователя в терминах MySQL}';

    protected $description = 'Сгенерировать SQL прав read-only пользователя для BI/ИИ-агента';

    /** Колонка, похожая на секрет, в BI не попадает. */
    private const SECRET_COLUMN_PATTERN = '/password|token|secret|hash|salt|private_key/i';

    /**
     * Ложные срабатывания шаблона: под него подходят, но секретами не являются.
     * Остальные совпадения (pulse_*.key_hash, telescope_entries.family_hash,
     * attribute_values.value_hash, product_exports.hash) сознательно не исключаем —
     * для отчётов они бесполезны, и прятать их бесплатно.
     */
    private const NOT_SECRET = [
        'users.must_change_password', // булев флаг «пароль просрочен», не сам пароль
    ];

    /** Таблицы, состоящие из секретов целиком: не выдаём ни напрямую, ни вьюхой. */
    private const SECRET_TABLES = [
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
        'api_tokens',
    ];

    /**
     * Инфраструктура фреймворка: очереди, кэш, метрики, история миграций.
     * Не секреты — но и не бизнес-данные: отчёт по выручке из pulse_entries не построить.
     * При этом их 12 из 95 таблиц, и в схеме, которую агент перечитывает перед каждым
     * запросом, они только разбавляют внимание. Если понадобится анализировать
     * производительность — убрать отсюда осознанно.
     */
    private const OPERATIONAL_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
    ];

    public function handle(): int
    {
        $connectionName = $this->option('connection');
        $connection = DB::connection($connectionName);

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error("Коннект «{$connectionName}»: нужен MySQL/MariaDB (драйвер: {$connection->getDriverName()}).");

            return self::FAILURE;
        }

        $database = $connection->getDatabaseName();
        $account = sprintf("'%s'@'%s'", $this->option('user'), $this->option('host'));

        $columns = $this->columnsByTable($connection, $database);
        if ($columns === []) {
            $this->error("В БД {$database} не найдено таблиц — проверьте коннект.");

            return self::FAILURE;
        }

        $lines = $this->header($database, $account);

        // Вьюхи-заглушки для секретных таблиц живут в ТОЙ ЖЕ БД, что и обычные
        // таблицы (не в отдельной схеме). Иначе агент видел бы orders в pecado без
        // префикса, а v_users в другой схеме — и промахивался бы: FROM v_users →
        // ERROR 1142, потому что дефолтная БД коннекта = pecado, а вьюхи не там.
        // В одной БД `FROM orders` и `FROM v_users` работают одинаково.
        $lines = array_merge($lines, $this->viewStatements($connection, $database, $columns));
        $lines = array_merge($lines, $this->grantStatements($database, $columns, $account));

        $this->line(implode(PHP_EOL, $lines));

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<string>> таблица => список колонок
     */
    private function columnsByTable(\Illuminate\Database\Connection $connection, string $database): array
    {
        // Псевдонимы обязательны: MySQL отдаёт столбцы information_schema в верхнем
        // регистре независимо от того, как они написаны в SELECT.
        $rows = $connection->table('information_schema.columns as c')
            ->join('information_schema.tables as t', function ($join) {
                $join->on('t.table_schema', '=', 'c.table_schema')
                    ->on('t.table_name', '=', 'c.table_name');
            })
            ->where('c.table_schema', $database)
            ->where('t.table_type', 'BASE TABLE') // вьюхи чужих схем сюда не тащим
            ->orderBy('c.table_name')
            ->orderBy('c.ordinal_position')
            ->get(['c.table_name as tbl', 'c.column_name as col']);

        $byTable = [];
        foreach ($rows as $row) {
            $byTable[$row->tbl][] = $row->col;
        }

        return $byTable;
    }

    /**
     * @param  array<string, list<string>>  $columns
     * @return array<string, list<string>> таблица => безопасные колонки
     */
    private function tablesWithSecrets(array $columns): array
    {
        $result = [];
        foreach ($columns as $table => $cols) {
            if ($this->exclusionReason($table) !== null) {
                continue;
            }
            $safe = array_values(array_filter($cols, fn ($c) => ! $this->isSecret($table, $c)));
            if (count($safe) !== count($cols)) {
                $result[$table] = $safe;
            }
        }

        return $result;
    }

    /** @return string|null причина, по которой таблица не выдаётся вовсе, либо null */
    private function exclusionReason(string $table): ?string
    {
        if (in_array($table, self::SECRET_TABLES, true)) {
            return 'состоит из секретов целиком';
        }
        if (in_array($table, self::OPERATIONAL_TABLES, true)) {
            return 'инфраструктура фреймворка, не бизнес-данные';
        }

        return null;
    }

    private function isSecret(string $table, string $column): bool
    {
        if (in_array("{$table}.{$column}", self::NOT_SECRET, true)) {
            return false;
        }

        return preg_match(self::SECRET_COLUMN_PATTERN, $column) === 1;
    }

    /**
     * @param  array<string, list<string>>  $columns
     * @return list<string>
     */
    private function viewStatements(\Illuminate\Database\Connection $connection, string $database, array $columns): array
    {
        $withSecrets = $this->tablesWithSecrets($columns);
        if ($withSecrets === []) {
            return [];
        }

        $lines = ['-- Вьюхи без секретных колонок. SQL SECURITY DEFINER (по умолчанию)'];
        $lines[] = '-- здесь обязателен: у '.$this->option('user').' нет прав на базовые таблицы,';
        $lines[] = '-- и с INVOKER такая вьюха вернула бы ошибку доступа вместо данных.';
        $lines[] = '';

        $expected = [];
        foreach ($withSecrets as $table => $safeColumns) {
            $view = 'v_'.$table;
            $expected[] = $view;

            $hidden = array_diff($columns[$table], $safeColumns);
            $lines[] = sprintf('-- %s: скрыто %s', $table, implode(', ', $hidden));

            // Список колонок разворачиваем явно: SELECT * раскрывается в момент
            // создания вьюхи, и новая колонка базовой таблицы в неё бы не попала.
            // Вьюха — в той же БД, что и таблица (v_users рядом с users).
            $lines[] = sprintf(
                'CREATE OR REPLACE VIEW `%s`.`%s` AS SELECT %s FROM `%s`.`%s`;',
                $database,
                $view,
                implode(', ', array_map(fn ($c) => "`{$c}`", $safeColumns)),
                $database,
                $table,
            );
        }

        // Вьюхи, потерявшие смысл (таблица удалена или секретных колонок больше нет),
        // иначе они остались бы висеть с правами DEFINER и доступом для bi_agent.
        foreach ($this->existingViews($connection, $database) as $stale) {
            if (! in_array($stale, $expected, true)) {
                $lines[] = sprintf('DROP VIEW IF EXISTS `%s`.`%s`; -- больше не нужна', $database, $stale);
            }
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function existingViews(\Illuminate\Database\Connection $connection, string $database): array
    {
        try {
            // Только наши вьюхи-заглушки (префикс v_), чтобы не зацепить чужие
            // вьюхи, если они появятся в этой БД.
            return $connection->table('information_schema.views')
                ->where('table_schema', $database)
                ->where('table_name', 'like', 'v\_%')
                ->pluck('table_name as tbl')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, list<string>>  $columns
     * @return list<string>
     */
    private function grantStatements(string $database, array $columns, string $account): array
    {
        $withSecrets = $this->tablesWithSecrets($columns);

        $lines = [
            '-- Чистый лист: гранты пересобираются целиком, чтобы права, выданные',
            '-- прошлым прогоном под другую схему, не пережили изменение.',
            "REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$account};",
            '',
        ];

        $granted = 0;
        foreach ($columns as $table => $_) {
            if (($reason = $this->exclusionReason($table)) !== null) {
                $lines[] = "-- {$table}: пропущена — {$reason}";

                continue;
            }
            if (isset($withSecrets[$table])) {
                continue; // доступ к ней — только через вьюху ниже
            }
            $lines[] = sprintf('GRANT SELECT ON `%s`.`%s` TO %s;', $database, $table, $account);
            $granted++;
        }

        foreach (array_keys($withSecrets) as $table) {
            $lines[] = sprintf('GRANT SELECT ON `%s`.`%s` TO %s;', $database, 'v_'.$table, $account);
            $granted++;
        }

        $lines[] = '';
        $lines[] = '-- Ресурсные лимиты. В отличие от max_execution_time (сессионная';
        $lines[] = '-- переменная — пользователь снимает её сам одной строкой) это';
        $lines[] = '-- ограничения уровня сервера, и обойти их клиент не может.';
        $lines[] = "ALTER USER {$account} WITH MAX_QUERIES_PER_HOUR 500 MAX_UPDATES_PER_HOUR 0 MAX_USER_CONNECTIONS 2;";
        $lines[] = '';
        $lines[] = 'FLUSH PRIVILEGES;';
        $lines[] = sprintf('-- Итого выдано объектов: %d', $granted);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function header(string $database, string $account): array
    {
        return [
            '-- Сгенерировано `php artisan bi:sync-grants` — руками не править.',
            '-- Права пересобираются из схемы, поэтому команду нужно прогонять',
            '-- после каждого migrate: новая таблица получит грант сама.',
            sprintf('-- БД: %s | пользователь: %s', $database, $account),
            '--',
            '-- Пользователь здесь НЕ создаётся: это потребовало бы печатать пароль',
            '-- в stdout, а оттуда он попал бы в логи деплоя. Завести один раз руками:',
            sprintf("--   CREATE USER %s IDENTIFIED BY '<пароль из DB_ANALYTICS_PASSWORD>';", $account),
            '',
        ];
    }
}
