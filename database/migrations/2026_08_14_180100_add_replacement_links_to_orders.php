<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь «заказ-замена → исходный заказ с недобором» (sub-01).
 *
 * Поле, а не эвристика по комментарию: «спасённая сумма» в аналитике считается
 * точным запросом по replacement_for_order_id, ради этого связь и заводится.
 * Протокол обмена с 1С не меняется — связь живёт только на сайте.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('replacement_for_order_id')
                ->nullable()
                ->after('parent_id')
                ->comment('Исходный заказ, недоборы которого закрывает этот заказ-замена (orders.id)')
                ->constrained('orders')
                ->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('replaces_order_item_id')
                ->nullable()
                ->after('cancelled')
                ->comment('Отменённая строка исходного заказа, которую закрывает эта строка-замена (order_items.id)')
                ->constrained('order_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_for_order_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_order_item_id');
        });
    }
};
