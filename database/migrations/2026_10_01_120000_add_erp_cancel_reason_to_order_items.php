<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Причина отмены строки заказа из 1С (`items[].cancel_reason`, протокол v16.17.0).
 *
 * Отдельная колонка, а не `cancel_reason_id`: там причина из справочника отдела
 * продаж, которую выбирает менеджер. Причина 1С — факт из документа, она грубее
 * (пять значений) и служит менеджеру подсказкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('erp_cancel_reason', 32)->nullable()->after('cancelled_at')
                ->comment("Причина отмены строки из 1С (items[].cancel_reason): 'out_of_stock' — нет свободного остатка, 'shortage' — недобор при сборке, 'client' — отмена клиентом сайта, 'reserve_expired' — истёк срок резерва, 'other' — другая причина 1С или неизвестное значение; NULL — строка не отменена или 1С причину не передала");
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('erp_cancel_reason');
        });
    }
};
