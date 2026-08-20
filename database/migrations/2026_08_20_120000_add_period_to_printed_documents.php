<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Период печатной формы (v16.6.0).
 *
 * Акт сверки строится за период, а не по документу-основанию: до сих пор период
 * был виден только внутри файла, и два акта одного контрагента ничем не различались
 * в списке. Поля заполняются из `period_from` / `period_to` сообщения 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printed_documents', function (Blueprint $table) {
            $table->date('period_from')->nullable()->after('date')
                ->comment('Начало периода, за который построена форма (акт сверки); null — форма по документу-основанию');
            $table->date('period_to')->nullable()->after('period_from')
                ->comment('Конец периода, за который построена форма; у акта сверки совпадает с date');
        });
    }

    public function down(): void
    {
        Schema::table('printed_documents', function (Blueprint $table) {
            $table->dropColumn(['period_from', 'period_to']);
        });
    }
};
