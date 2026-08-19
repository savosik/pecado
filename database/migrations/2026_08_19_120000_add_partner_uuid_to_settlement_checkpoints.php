<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Партнёр в ключе контрольной точки (круг 11).
 *
 * 1С считает точку в разрезе партнёра, а один контрагент может быть привязан
 * к двум партнёрам: по Войдакову Д.Е. пришли две точки на одну пару
 * «контрагент × организация» — −13 647,75 и −4 955,00, вместе −18 602,75,
 * ровно как в нашей ленте. Ключ без партнёра принимал их за повтор, и вторая
 * строка затирала первую: сверка показывала расхождение там, где данные целы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_checkpoints', function (Blueprint $table) {
            $table->string('partner_uuid', 36)->default('')->after('user_id')
                ->comment('UUID партнёра в 1С — часть ключа точки: один контрагент может принадлежать двум партнёрам');

            $table->dropUnique('sc_contractor_org_currency_date_unique');
            $table->unique(
                ['contractor_uuid', 'partner_uuid', 'organization_uuid', 'currency_code', 'as_of_date'],
                'sc_contractor_partner_org_currency_date_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('settlement_checkpoints', function (Blueprint $table) {
            $table->dropUnique('sc_contractor_partner_org_currency_date_unique');
            $table->unique(
                ['contractor_uuid', 'organization_uuid', 'currency_code', 'as_of_date'],
                'sc_contractor_org_currency_date_unique',
            );
            $table->dropColumn('partner_uuid');
        });
    }
};
