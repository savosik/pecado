<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Организация и склад реализации (эпик org-00, карточка org-04).
 *
 * Симметрично заказам, но реализация важнее двух вещей: по ней клиент сверяет
 * накладную, и именно по `shipment_items` считается аналитика продаж (org-09).
 *
 * Организация реализации может отличаться от организации её заказа — это легитимно,
 * 1С могла переоформить документ. Сайт принимает оба значения как есть.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('company_id')
                ->comment('Наша организация, от имени которой проведена реализация в 1С (organizations.id). NULL — не указана')
                ->constrained('organizations')->nullOnDelete();

            $table->foreignId('warehouse_id')->nullable()->after('organization_id')
                ->comment('Склад отгрузки реализации, определённый 1С (warehouses.id). NULL — не указан или неизвестен сайту')
                ->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['organization_id', 'warehouse_id']);
        });
    }
};
