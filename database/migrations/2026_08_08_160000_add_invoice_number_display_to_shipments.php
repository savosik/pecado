<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Печатный номер счёта-фактуры (протокол v15.16.1).
 *
 * В 1С у счёта-фактуры два номера: реквизит «Номер» («29УТ-0006968») — внутренний,
 * с префиксом организации и ведущими нулями, и «ПредставлениеНомера» («УТ-6968») —
 * тот, что печатается на бумаге.
 *
 * Клиенту показываем печатный: бухгалтерия сверяет по форме, которую держит в руках.
 * Внутренний храним рядом — по нему сверяемся с 1С, когда расходятся данные.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('invoice_number_display')->nullable()->after('invoice_number')
                ->comment('Печатный номер счёта-фактуры («УТ-6968») — ПредставлениеНомера из 1С. Показывается клиенту: бухгалтерия сверяет по бумаге, а не по внутреннему номеру базы');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('invoice_number_display');
        });
    }
};
