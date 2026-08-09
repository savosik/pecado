<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ячейка хранения по строке расходного ордера (протокол v15.17.0).
 *
 * Значение приезжает из 1С строкой как есть: адресное хранение ведётся в 1С,
 * сайт только показывает ячейку кладовщику и со справочниками её не сверяет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_issue_items', function (Blueprint $table) {
            $table->string('cell', 255)->nullable()->after('unit')
                ->comment('Ячейка хранения по строке из 1С («Ячейка» табличной части), например «А-01-02-03»; справочника ячеек на сайте нет — строка как есть');
        });
    }

    public function down(): void
    {
        Schema::table('goods_issue_items', function (Blueprint $table) {
            $table->dropColumn('cell');
        });
    }
};
