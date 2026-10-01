<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отгруженный без товара расходный ордер (протокол v16.15.0, топик Agent Hub №19).
 *
 * Полный недобор: упаковщик оформил недобор, а собрано ничего. 1С присылает `shipped`
 * с `items: []`. Строки прошлых снимков сайт сохраняет — по ним ордер привязан к заказам, —
 * а этот признак отличает такой ордер от собранного: письма «ждёт выдачи» нет, в очередь
 * выдачи он не встаёт, стадия заказа — «Сборка не состоялась».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_issues', function (Blueprint $table) {
            $table->boolean('shipped_empty')->default(false)->after('status')
                ->comment('Отгружен без товара (полный недобор, v16.15.0): 1С прислала status=shipped с пустыми items; строки — последний снимок сборки до недобора');
        });
    }

    public function down(): void
    {
        Schema::table('goods_issues', function (Blueprint $table) {
            $table->dropColumn('shipped_empty');
        });
    }
};
