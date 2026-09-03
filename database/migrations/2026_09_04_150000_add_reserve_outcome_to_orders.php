<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Исход резерва (res-11): источник истины для метрик злоупотреблений.
 *
 * Флаг orders.reserve при подтверждении/отмене обнуляется, поэтому постфактум
 * «как закончился резерв» без отдельного поля не восстановить.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('reserve_outcome', 12)->nullable()->after('items_version')
                ->comment("Исход резерва (v16.9.0): 'confirmed' — клиент подтвердил отгрузку, 'cancelled' — отменил сам, 'expired' — снят по таймауту. NULL — заказ не был в резерве либо резерв ещё активен");
            $table->index(['user_id', 'reserve_outcome'], 'orders_reserve_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_reserve_outcome_index');
            $table->dropColumn('reserve_outcome');
        });
    }
};
