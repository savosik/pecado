<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отправки, оформленные без ApiShip.
 *
 * Система внедряется задним числом: часть груза склад уже отправил, а часть
 * перевозчиков вообще не подключена к агрегатору — заявку делают на сайте ТК
 * или по телефону. Без ручной отметки такие реализации вечно висели бы
 * в кандидатах на отправку, и списку нельзя было бы верить.
 *
 * Ручная отправка живёт по тем же правилам, что и обычная: занимает реализации,
 * несёт трек и статус. Отличается только тем, что в ApiShip её нет — поэтому
 * ни расчёт, ни этикетка, ни вызов курьера для неё не работают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_shipments', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('status')
                ->comment('Отправка оформлена вручную, минуя ApiShip: заявку делали на сайте ТК или по телефону');
            $table->string('carrier_name', 150)->nullable()->after('provider_key')
                ->comment('Название перевозчика как его вписал склад. Для ручных отправок — единственный источник, у остальных дублирует provider_key');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_shipments', function (Blueprint $table) {
            $table->dropColumn(['is_manual', 'carrier_name']);
        });
    }
};
