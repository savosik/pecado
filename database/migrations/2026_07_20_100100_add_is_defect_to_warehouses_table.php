<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Признак склада некондиции.
 *
 * Заказы уценки уходят в 1С с warehouse_uuids этого склада. Завязываемся на флаг,
 * а не на UUID в конфиге: на момент миграции external_id склада «Москва некондиция»
 * ещё не получен от 1С, а сам склад в БД уже заведён.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('is_defect')->default(false)->after('external_id')
                ->comment('Склад некондиции: с него отгружаются заказы уценки (orders.type = defect)');
        });

        // Склад заведён вручную на prod до появления этой фичи — помечаем по имени.
        DB::table('warehouses')->where('name', 'Москва некондиция')->update(['is_defect' => true]);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('is_defect');
        });
    }
};
