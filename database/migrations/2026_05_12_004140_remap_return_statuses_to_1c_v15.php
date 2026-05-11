<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Приведение статусов заявки на возврат к перечислению 1С:КА2 (v15).
 *
 * Маппинг:
 *   pending       → pending_approval
 *   confirmed     → in_reserve         (КОбеспечению — более ранний этап)
 *   ready_to_ship → ready_for_shipment
 *   closed        → completed
 *   cancelled     → rejected
 *
 * До v15 `confirmed` объединял два этапа 1С — «КОбеспечению» и «КВозврату».
 * Информации, чтобы их разделить ретроспективно, нет, поэтому все
 * `confirmed` уходят в `in_reserve`. Менеджер в 1С при необходимости
 * вручную переведёт нужные заявки в `for_return`.
 */
return new class extends Migration
{
    private const MAP = [
        'pending' => 'pending_approval',
        'confirmed' => 'in_reserve',
        'ready_to_ship' => 'ready_for_shipment',
        'closed' => 'completed',
        'cancelled' => 'rejected',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('returns')->where('status', $old)->update(['status' => $new]);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE returns MODIFY COLUMN status VARCHAR(255) NOT NULL DEFAULT 'pending_approval'");
        }
    }

    public function down(): void
    {
        $reverse = [
            'pending_approval' => 'pending',
            'for_return' => 'confirmed',
            'in_reserve' => 'confirmed',
            'ready_for_shipment' => 'ready_to_ship',
            'completed' => 'closed',
            'rejected' => 'cancelled',
        ];

        foreach ($reverse as $new => $old) {
            DB::table('returns')->where('status', $new)->update(['status' => $old]);
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE returns MODIFY COLUMN status VARCHAR(255) NOT NULL DEFAULT 'pending'");
        }
    }
};
