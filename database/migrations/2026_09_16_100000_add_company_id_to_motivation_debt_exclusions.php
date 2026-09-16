<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Исключение долга по контрагенту.
 *
 * В 1С долг висит на юрлице, а у партнёра их бывает несколько (сеть): списать
 * или передать в претензионную работу можно долг одного юрлица, не трогая
 * остальных. Третий уровень между «накладная» и «партнёр целиком».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motivation_debt_exclusions', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('shipment_id')
                ->comment('Контрагент партнёра (companies.id); задан — исключены все накладные этого юрлица. NULL при исключении накладной или партнёра целиком')
                ->constrained('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('motivation_debt_exclusions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
