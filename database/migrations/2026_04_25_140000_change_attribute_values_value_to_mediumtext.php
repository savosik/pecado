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

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropUnique(['attribute_id', 'value']);
        });

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->char('value_hash', 64)->nullable()->after('value');
        });

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE attribute_values MODIFY value MEDIUMTEXT NOT NULL');
            DB::statement('UPDATE attribute_values SET value_hash = SHA2(value, 256) WHERE value_hash IS NULL');
        } else {
            // SQLite (тесты): TEXT уже принимает любую длину, хеш считаем в PHP.
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

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->char('value_hash', 64)->nullable(false)->change();
            $table->unique(['attribute_id', 'value_hash']);
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropUnique(['attribute_id', 'value_hash']);
        });

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('DELETE FROM attribute_values WHERE CHAR_LENGTH(value) > 255');
            DB::statement('ALTER TABLE attribute_values MODIFY value VARCHAR(255) NOT NULL');
        }

        Schema::table('attribute_values', function (Blueprint $table) {
            $table->dropColumn('value_hash');
            $table->unique(['attribute_id', 'value']);
        });
    }
};
