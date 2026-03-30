<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяем поле country в таблице companies с 3 стран (RU, BY, KZ)
 * до всех стран СНГ (12 значений).
 *
 * MySQL: меняем ENUM через изменение колонки (doctrine/dbal).
 * SQLite: меняем через dropIndex + dropColumn + addColumn (не поддерживает ALTER ENUM).
 */
return new class extends Migration
{
    private const CIS = ['RU', 'BY', 'KZ', 'UA', 'UZ', 'AZ', 'AM', 'GE', 'KG', 'MD', 'TJ', 'TM'];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('companies', function (Blueprint $table) {
                // Дропаем индекс на country (он мешает dropColumn в SQLite)
                $table->dropIndex('companies_country_index');
            });
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('country');
            });
            Schema::table('companies', function (Blueprint $table) {
                $table->string('country', 2)->after('legal_name');
            });
        } else {
            Schema::table('companies', function (Blueprint $table) {
                $table->enum('country', self::CIS)->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('companies', function (Blueprint $table) {
                $table->enum('country', ['RU', 'BY', 'KZ'])->change();
            });
        }
    }
};
