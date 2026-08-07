<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-18 (v15.13.0): снимок себестоимости в строке реализации.
 *
 * Себестоимость меняется во времени, а реализация — исторический документ. Без снимка
 * отчёт по прибыли за прошлый период пересчитывался бы при каждом cost.updated.
 * Заполняется в ShipmentItem::fillSnapshotFields() при создании строки.
 *
 * Бэкфилла нет: истории себестоимости не существует ни на сайте, ни в контракте с 1С —
 * у строк, созданных до этой миграции, значение остаётся NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            $table->decimal('cost_price_snapshot', 15, 2)->nullable()->after('brand_name_snapshot')
                ->comment('Себестоимость товара на момент создания строки реализации (products.cost_price)');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            $table->dropColumn('cost_price_snapshot');
        });
    }
};
