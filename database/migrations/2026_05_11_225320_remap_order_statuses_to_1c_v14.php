<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Приведение статусов заказа к перечислению 1С:КА2 (v14).
 *
 * Маппинг:
 *   pending       → pending_approval
 *   confirmed     → ready_for_provision
 *   ready_to_ship → ready_for_shipment
 *   closed        → closed (без изменений)
 *   deleted       → closed + soft-delete (deleted_at = NOW(), если ещё не задан)
 *
 * Также пересчитываются записи в `order_status_histories` (поля
 * `old_status` / `new_status`) и история в `order_change_logs`,
 * если она ссылается на статус.
 */
return new class extends Migration
{
    private const MAP = [
        'pending' => 'pending_approval',
        'confirmed' => 'ready_for_provision',
        'ready_to_ship' => 'ready_for_shipment',
        'deleted' => 'closed',
    ];

    public function up(): void
    {
        // 1) soft-delete для заказов со status='deleted' (если ещё не помечены)
        DB::table('orders')
            ->where('status', 'deleted')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        // 2) маппинг статусов в orders
        foreach (self::MAP as $old => $new) {
            DB::table('orders')->where('status', $old)->update(['status' => $new]);
        }

        // 3) маппинг старых значений в истории статусов
        if (DB::getSchemaBuilder()->hasTable('order_status_histories')) {
            foreach (self::MAP as $old => $new) {
                DB::table('order_status_histories')->where('old_status', $old)->update(['old_status' => $new]);
                DB::table('order_status_histories')->where('new_status', $old)->update(['new_status' => $new]);
            }
        }

        // 4) дефолтное значение колонки status
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status VARCHAR(255) NOT NULL DEFAULT 'pending_approval'");
        }
    }

    public function down(): void
    {
        $reverse = [
            'pending_approval' => 'pending',
            'pending_payment_before_provision' => 'confirmed',
            'ready_for_provision' => 'confirmed',
            'pending_payment_before_shipment' => 'confirmed',
            'awaiting_provision' => 'confirmed',
            'ready_for_shipment' => 'ready_to_ship',
            'shipping' => 'ready_to_ship',
            'awaiting_payment' => 'confirmed',
            'ready_for_closure' => 'confirmed',
            'closed' => 'closed',
        ];

        foreach ($reverse as $new => $old) {
            DB::table('orders')->where('status', $new)->update(['status' => $old]);
        }

        if (DB::getSchemaBuilder()->hasTable('order_status_histories')) {
            foreach ($reverse as $new => $old) {
                DB::table('order_status_histories')->where('old_status', $new)->update(['old_status' => $old]);
                DB::table('order_status_histories')->where('new_status', $new)->update(['new_status' => $old]);
            }
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status VARCHAR(255) NOT NULL DEFAULT 'pending'");
        }
    }
};
