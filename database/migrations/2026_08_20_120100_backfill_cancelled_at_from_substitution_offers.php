<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Даты отмен из подборок замен — в журнал недоборов.
 *
 * Подборка рождалась ровно в момент, когда сайт увидел отмену строки, поэтому
 * `created_at` её строки-недобора (kind = 'line') — это и есть дата отмены.
 * Другого источника у истории нет: у самой строки заказа времени отмены
 * до сих пор не хранилось.
 *
 * Отмены старше подборок (до 14.08.2026) остаются без даты — журнал показывает
 * такие строки с прочерком. Домысливать дату из `updated_at` строки нельзя:
 * он меняется при каждом `order.updated`, то есть врал бы уверенно.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('substitution_offer_items')) {
            return;
        }

        DB::table('substitution_offer_items')
            ->where('kind', 'line')
            ->whereNotNull('source_order_item_id')
            ->orderBy('id')
            ->select('source_order_item_id', 'created_at')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('order_items')
                        ->where('id', $row->source_order_item_id)
                        ->where('cancelled', true)
                        ->whereNull('cancelled_at')
                        ->update(['cancelled_at' => $row->created_at]);
                }
            });
    }

    public function down(): void
    {
        // Обратной операции нет: восстановить, какая из дат пришла из подборок,
        // после удаления их таблиц невозможно.
    }
};
