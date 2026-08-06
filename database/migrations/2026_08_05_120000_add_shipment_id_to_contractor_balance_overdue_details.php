<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Связь строки просрочки с реализацией сайта.
 *
 * Раньше детализация хранила только `shipment_uuid` из 1С, поэтому в админке
 * вместо номера реализации выводился голый UUID. Теперь рядом лежит FK.
 *
 * FK именно nullable: баланс и реализации приезжают из 1С разными очередями
 * (`erp_in.balances` / `erp_in.shipments`) без гарантии порядка, так что строка
 * просрочки может появиться раньше самой реализации. `shipment_uuid` остаётся
 * источником правды — по нему связь доклеивается позже (см. HandleShipmentCreated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractor_balance_overdue_details', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('organization_id')
                ->comment('Реализация сайта, соответствующая shipment_uuid (shipments.id). NULL — реализация ещё не пришла из 1С')
                ->constrained('shipments')->nullOnDelete();

            $table->index('shipment_uuid', 'cbod_shipment_uuid_index');
        });

        // Backfill уже накопленных строк по UUID реализации. Коррелированный
        // подзапрос вместо UPDATE ... JOIN — последний не понимает SQLite,
        // на котором гоняются тесты.
        DB::table('contractor_balance_overdue_details')
            ->whereNull('shipment_id')
            ->update([
                'shipment_id' => DB::raw(
                    '(select id from shipments'
                    .' where shipments.uuid = contractor_balance_overdue_details.shipment_uuid'
                    .' and shipments.deleted_at is null limit 1)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('contractor_balance_overdue_details', function (Blueprint $table) {
            $table->dropForeign(['shipment_id']);
            $table->dropColumn('shipment_id');
            $table->dropIndex('cbod_shipment_uuid_index');
        });
    }
};
