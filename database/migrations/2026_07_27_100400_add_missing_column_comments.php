<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Дозаполнение комментариев, пропущенных в миграциях аналитики и пресетов CRM.
 *
 * Проверяется командой db:comments:audit --strict; правим DDL напрямую,
 * чтобы не задеть типы и внешние ключи (см. .claude/rules/db-comments.md).
 */
return new class extends Migration
{
    /** @var array<string, array<string, array{0: string, 1: string}>> Таблица → столбец → [DDL типа, комментарий] */
    private const COMMENTS = [
        'analytics_tokens' => [
            'created_at' => ['timestamp NULL DEFAULT NULL', 'Дата и время создания записи'],
            'updated_at' => ['timestamp NULL DEFAULT NULL', 'Дата и время последнего изменения записи'],
        ],
        'crm_analytics_filter_presets' => [
            'user_id' => ['bigint unsigned NOT NULL', 'Владелец пресета (users.id)'],
            'created_at' => ['timestamp NULL DEFAULT NULL', 'Дата и время создания записи'],
            'updated_at' => ['timestamp NULL DEFAULT NULL', 'Дата и время последнего изменения записи'],
        ],
    ];

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COMMENTS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => [$definition, $comment]) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::statement(sprintf(
                    'ALTER TABLE `%s` MODIFY COLUMN `%s` %s COMMENT %s',
                    $table,
                    $column,
                    $definition,
                    DB::getPdo()->quote($comment),
                ));
            }
        }
    }

    public function down(): void
    {
        // Комментарии не откатываем: их отсутствие — не состояние, к которому стоит возвращаться
    }
};
