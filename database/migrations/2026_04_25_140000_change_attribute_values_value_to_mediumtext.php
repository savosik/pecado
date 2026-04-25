<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // 1) Добавляем value_hash как nullable, чтобы пройти бэкфилл без ошибок NOT NULL.
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->char('value_hash', 64)->nullable()->after('value');
        });

        // 2) Бэкфилл хешей.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('UPDATE attribute_values SET value_hash = SHA2(value, 256) WHERE value_hash IS NULL');
        } else {
            DB::table('attribute_values')
                ->whereNull('value_hash')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('attribute_values')
                            ->where('id', $row->id)
                            ->update(['value_hash' => hash('sha256', (string) $row->value)]);
                    }
                });
        }

        // 3) Сначала создаём новый UNIQUE — у него первая колонка attribute_id,
        //    поэтому MySQL сможет использовать его для FK-lookup на attribute_id
        //    после удаления старого индекса.
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->char('value_hash', 64)->nullable(false)->change();
            $table->unique(['attribute_id', 'value_hash']);
        });

        // 4) Теперь старый UNIQUE можно дропнуть — FK на attribute_id найдёт
        //    замену в новом индексе.
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropUnique(['attribute_id', 'value']);
        });

        // 5) Расширяем сам value до MEDIUMTEXT (только MySQL — в SQLite TEXT уже неограничен).
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE attribute_values MODIFY value MEDIUMTEXT NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // VARCHAR(255) обратно — но строки длиннее 255 не уместятся, удаляем заранее.
            DB::statement('DELETE FROM attribute_values WHERE CHAR_LENGTH(value) > 255');
            DB::statement('ALTER TABLE attribute_values MODIFY value VARCHAR(255) NOT NULL');
        }

        // Возвращаем старый UNIQUE до того, как дропнем новый — чтобы FK на attribute_id
        // сразу нашёл индекс для использования.
        Schema::table('attribute_values', function (Blueprint $table) {
            $table->unique(['attribute_id', 'value']);
        });

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropUnique(['attribute_id', 'value_hash']);
            $table->dropColumn('value_hash');
        });
    }
};
