<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v10: Добавляет флаг «Скрыть в интернете» (hidden) в таблицу products.
 * Товары с hidden = true не отображаются на сайте (ни в каталоге, ни в поиске).
 * Источник: US-15/US-17 (ACCEPTANCE_CRITERIA_v10.md)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('model_id')
                ->comment('v10: Скрыть в интернете — товар не отображается на сайте');
            $table->index('hidden');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['hidden']);
            $table->dropColumn('hidden');
        });
    }
};
