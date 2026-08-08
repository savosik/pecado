<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счёт-фактура реализации и комментарий платежа из 1С (протокол v15.16.0).
 *
 * Оба поля 1С уже присылает — места в контракте под них не было:
 *
 *  - счёт-фактура: все двенадцать вхождений слова `invoice` в спецификации
 *    относились к значению `invoice_date` перечисления `basis` графика оплаты,
 *    то есть к совпадению имён, а не к документу. Данные при этом нужны
 *    бухгалтерии клиента;
 *  - комментарий платежа: в 1С заполнен во всех 2 841 платёжных документах
 *    за 2026 год.
 *
 * Комментарий 1С кладём в ОТДЕЛЬНУЮ колонку `erp_comment`, а не в `comment`:
 * `payments.comment` принадлежит сайту — это единственное поле платежа, которое
 * ведёт сотрудник, и оно в 1С не уходит. Общая колонка означала бы, что первая
 * же доставка документа стирает заметку менеджера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('erp_number')
                ->comment('Номер счёта-фактуры из 1С. Нужен бухгалтерии клиента, в логике сайта не участвует');
            $table->date('invoice_date')->nullable()->after('invoice_number')
                ->comment('Дата счёта-фактуры из 1С');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->text('erp_comment')->nullable()->after('comment')
                ->comment('Комментарий к платёжному документу из 1С. Отдельно от comment: тот ведёт сотрудник сайта и 1С его не перезаписывает');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'invoice_date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('erp_comment');
        });
    }
};
