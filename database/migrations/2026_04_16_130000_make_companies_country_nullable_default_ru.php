<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1С не передаёт поле country компании в order.created,
     * что приводит к SQLSTATE[23000]: Column 'country' cannot be null.
     * Делаем колонку nullable и устанавливаем DEFAULT 'RU'.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('country', ['RU', 'BY', 'KZ', 'UA', 'UZ', 'AZ', 'AM', 'GE', 'KG', 'MD', 'TJ', 'TM'])->nullable()->default('RU')->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('country', ['RU', 'BY', 'KZ', 'UA', 'UZ', 'AZ', 'AM', 'GE', 'KG', 'MD', 'TJ', 'TM'])->nullable(false)->default(null)->change();
        });
    }
};
