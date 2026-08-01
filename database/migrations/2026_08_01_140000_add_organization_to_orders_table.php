<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Организация и склад проведения заказа (эпик org-00, карточка org-03).
 *
 * Оба поля заполняет только 1С: сайт организацию не определяет и не отправляет,
 * а склады при оформлении уходят перечислением UUID-ов региона — конкретный
 * выбирает 1С и возвращает обратно в `warehouse_uuid`.
 *
 * Nullable без значения по умолчанию: NULL значит «не указана», а не «неизвестная».
 * Историю догадками не бэкфиллим — в отчётах это отдельная группа «не указана».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('company_id')
                ->comment('Наша организация, на которую заказ проведён в 1С (organizations.id). NULL — не указана')
                ->constrained('organizations')->nullOnDelete();

            $table->foreignId('warehouse_id')->nullable()->after('organization_id')
                ->comment('Склад отгрузки, определённый 1С (warehouses.id). NULL — не указан или неизвестен сайту')
                ->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['organization_id', 'warehouse_id']);
        });
    }
};
