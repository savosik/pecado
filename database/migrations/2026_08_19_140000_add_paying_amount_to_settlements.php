<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ресурс «Оплачивается» из 1С (спека v16.4.0, итог круга 11).
 *
 * Живой долг пары в 1С считается как `SUM(amount) - SUM(paying_amount)`, а баланс
 * ленты — по-прежнему `SUM(amount)`. Пока ресурс не хранился, эти два определения
 * расходились системно, и сверка ловила разницу на целых данных.
 *
 * Колонки nullable: сообщения v16.0 поля не несут вовсе, и «нет данных» здесь
 * не то же самое, что «ноль» — иначе старые движения выдавали бы себя за
 * полностью неоплаченные.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_entries', function (Blueprint $table) {
            $table->decimal('paying_amount', 20, 2)->nullable()->after('settled_amount')
                ->comment('Ресурс «Оплачивается» строки регистра 1С (≥ 0). Живой долг пары = SUM(amount) − SUM(paying_amount); в баланс ленты не входит. NULL — сообщение старее v16.4');
        });

        Schema::table('settlement_checkpoints', function (Blueprint $table) {
            $table->decimal('paying_amount', 20, 2)->nullable()->after('amount_rub')
                ->comment('Σ ресурса «Оплачивается» по паре на дату отсечки (≥ 0). Живой нетто-остаток пары = amount − paying_amount. NULL — сообщение старее v16.4');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_entries', function (Blueprint $table) {
            $table->dropColumn('paying_amount');
        });

        Schema::table('settlement_checkpoints', function (Blueprint $table) {
            $table->dropColumn('paying_amount');
        });
    }
};
