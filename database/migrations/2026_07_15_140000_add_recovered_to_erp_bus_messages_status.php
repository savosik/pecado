<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v15.4: статус `recovered` в логе шины ERP.
 *
 * Означает: сообщение обработано, но потребовало восстановления сущности —
 * 1С потеряла событие создания, и сайт достроил сущность из payload обновления.
 * См. docs-erp/content/rules/orders.md, раздел «Самовосстановление заказа».
 *
 * Через change(), а не ALTER: на MySQL колонка — ENUM, на SQLite (тесты) —
 * varchar с CHECK-констрейнтом. Оба нужно расширить, иначе запись со статусом
 * `recovered` не пройдёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_bus_messages', function (Blueprint $table) {
            $table->enum('status', ['success', 'recovered', 'failed'])
                ->default('success')
                ->comment("Статус обработки сообщения: 'success' — успех, 'recovered' — обработано с восстановлением сущности (1С потеряла событие создания), 'failed' — ошибка")
                ->change();
        });
    }

    public function down(): void
    {
        // Записи recovered — успешно обработанные, при откате приравниваем к success,
        // иначе они не пройдут сужённый набор значений.
        DB::table('erp_bus_messages')->where('status', 'recovered')->update(['status' => 'success']);

        Schema::table('erp_bus_messages', function (Blueprint $table) {
            $table->enum('status', ['success', 'failed'])
                ->default('success')
                ->comment('Статус обработки сообщения')
                ->change();
        });
    }
};
