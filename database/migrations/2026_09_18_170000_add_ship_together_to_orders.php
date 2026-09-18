<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Совместная отгрузка резервов (протокол v16.11.0, топик №7 Agent Hub).
 *
 * Клиент отправляет несколько резервов в отгрузку одной группой; 1С копит группу
 * по манифесту и отвечает одним итогом на всю группу. Пока итога нет, сайт держит
 * резерв локально и закрывает правки — состояние ожидания хранится на заказе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('ship_together_key')->nullable()->after('reserve_outcome')
                ->comment('Ключ группы совместной отгрузки (v16.11.0): одинаков у всех заказов группы, ушёл в 1С в order.confirmed. NULL — заказ отправлялся в отгрузку по одному или ещё в резерве');
            $table->string('ship_together_status', 12)->nullable()->after('ship_together_key')
                ->comment("Состояние группы совместной отгрузки (v16.11.0): 'pending' — отправлено, ждём итог 1С (резерв держится, правки закрыты), 'confirmed' — 1С подтвердила группу, 'conflict' — 1С отклонила группу целиком, заказ снова в резерве");
            $table->json('ship_together_conflict')->nullable()->after('ship_together_status')
                ->comment('Причина отказа группы из order.updated 1С (v16.11.0): {reason, message, order_uuid}. Коды reason: timeout, manifest_mismatch, not_reserved, incompatible, merge_forbidden, shortage, processing_error');
            $table->timestamp('ship_together_sent_at')->nullable()->after('ship_together_conflict')
                ->comment('Когда группа ушла в 1С (v16.11.0) — для страховочного тайм-аута сайта, если итог так и не пришёл');
            $table->index(['ship_together_key'], 'orders_ship_together_key_index');
            $table->index(['ship_together_status', 'ship_together_sent_at'], 'orders_ship_together_pending_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_ship_together_pending_index');
            $table->dropIndex('orders_ship_together_key_index');
            $table->dropColumn(['ship_together_key', 'ship_together_status', 'ship_together_conflict', 'ship_together_sent_at']);
        });
    }
};
