<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TIMESTAMP в MySQL живёт в 1970..2038. 1С нередко присылает «пустые»
     * сроки годности как 1900-01-01 или 0001-01-01 — на TIMESTAMP это валится
     * SQLSTATE[22007]. Меняем на DATETIME (1000..9999), чтобы импорт не падал;
     * фильтрация стабов делается в HandleProduct{Created,Updated}.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('product_attribute_values', 'datetime_value')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `product_attribute_values` MODIFY `datetime_value` DATETIME NULL');

            return;
        }

        // SQLite (тесты) хранит даты как TEXT — миграция не требуется.
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_attribute_values', 'datetime_value')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `product_attribute_values` MODIFY `datetime_value` TIMESTAMP NULL');
        }
    }
};
