<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Склад, зафиксированный сайтом при оформлении заказа в регионе с режимом
     * стопки складов. Отдельная колонка, а не orders.warehouse_id: тот —
     * «факт проведения», его присылает только 1С, и roundtrip-обновления
     * документа не должны затирать назначение сайта.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('assigned_warehouse_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('warehouses')
                ->nullOnDelete()
                ->comment('Склад, зафиксированный сайтом при оформлении (режим стопки складов региона; warehouses.id). NULL — регион без стопки. Не путать с warehouse_id — фактом проведения из 1С');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_warehouse_id');
        });
    }
};
