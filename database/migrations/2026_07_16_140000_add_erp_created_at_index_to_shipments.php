<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс под бизнес-дату (erp_created_at) для CRM-отчёта продаж, который
 * агрегирует отгрузки по набору клиентов менеджера/всего отдела.
 * Композит (user_id, erp_created_at) закрывает основной паттерн: скоуп по
 * клиентам + диапазон дат документа 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->index(['user_id', 'erp_created_at'], 'shipments_user_erp_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('shipments_user_erp_created_idx');
        });
    }
};
